<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\Document;
use App\Models\Position;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WorkspaceSetting;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class WealthDeskTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config(['app.env' => 'local']);
    }

    public function test_command_refuses_non_local_environment_without_writes(): void
    {
        $this->app['env'] = 'testing';
        config(['app.env' => 'testing']);

        $this->artisan('wealthdesk:test-data')->assertExitCode(1);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Client::count());
        $this->assertSame(0, WorkspaceSetting::count());
    }

    public function test_command_creates_related_fictional_data_and_is_safe_to_rerun(): void
    {
        $adminPassword = $this->temporaryPassword();
        $clientPassword = $this->temporaryPassword();
        $this->artisan('wealthdesk:test-data')
            ->expectsQuestion('Test administrator password (hidden input)', $adminPassword)
            ->expectsQuestion('Shared test-client password (hidden input)', $clientPassword)
            ->assertExitCode(0);

        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame(20, Client::count());
        $this->assertSame(72, Position::count());
        $this->assertSame(40, Deposit::count());
        $this->assertSame(20, Withdrawal::count());
        $this->assertSame(0, Document::count());
        $this->assertSame(1, WorkspaceSetting::count());
        $this->assertSame(18, Client::where('archived', false)->count());
        $this->assertSame(2, Client::where('archived', true)->count());
        $this->assertSame(6, Withdrawal::where('status', 'Pending')->count());
        $this->assertSame(7, Withdrawal::where('status', 'Approved')->count());
        $this->assertSame(7, Withdrawal::where('status', 'Declined')->count());

        $admin = User::where('role', 'admin')->firstOrFail();
        $this->assertSame('wealthdesk.qa.admin@example.com', $admin->email);
        $this->assertTrue(Hash::check($adminPassword, $admin->password));
        $first = User::where('email', 'wealthdesk.qa.client01@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($clientPassword, $first->password));
        $storedClientHash = DB::table('users')->where('id', $first->id)->value('password');
        $storedClientPassword = DB::table('users')->where('id', $first->id)->value('client_password');
        $this->assertNotSame($clientPassword, $storedClientHash);
        $this->assertNotNull($storedClientPassword);
        $this->assertNotSame($clientPassword, $storedClientPassword);
        $this->assertSame('MFSLQA01', $first->client->code);
        $this->assertTrue($first->client->sample);
        $this->assertSame('example.com', substr(strrchr($first->email, '@'), 1));
        $this->assertSame('MARWADI FINANCE QA Workspace', WorkspaceSetting::first()->name);

        $ledger = app(Ledger::class);
        foreach (Client::with('user')->get() as $client) {
            $this->assertSame($client->user_id, $client->user->id);
            $balance = $ledger->balance($client);
            $this->assertGreaterThanOrEqual(0, $balance['available']);
            $this->assertSame(
                $client->investment + $balance['deposits'] + $balance['realized'] - $balance['approved'],
                $balance['balance']
            );
            $this->assertSame($balance['balance'] - $balance['pending'], $balance['available']);
            $this->assertSame($client->id, $client->positions()->firstOrFail()->client_id);
            $this->assertSame($client->id, $client->deposits()->firstOrFail()->client_id);
            $this->assertSame($client->id, $client->withdrawals()->firstOrFail()->client_id);
        }
        $this->assertSame(0, DB::table('clients')->leftJoin('users', 'users.id', '=', 'clients.user_id')->whereNull('users.id')->count());
        $this->assertSame(0, DB::table('positions')->leftJoin('clients', 'clients.id', '=', 'positions.client_id')->whereNull('clients.id')->count());
        $this->assertSame(0, DB::table('deposits')->leftJoin('clients', 'clients.id', '=', 'deposits.client_id')->whereNull('clients.id')->count());
        $this->assertSame(0, DB::table('withdrawals')->leftJoin('clients', 'clients.id', '=', 'withdrawals.client_id')->whereNull('clients.id')->count());
        $this->assertSame(0, DB::table('audit_events')->leftJoin('users', 'users.id', '=', 'audit_events.actor_id')->whereNotNull('audit_events.actor_id')->whereNull('users.id')->count());
        $this->assertGreaterThan(0, AuditEvent::count());

        $before = [User::count(), Client::count(), Position::count(), Deposit::count(), Withdrawal::count(), AuditEvent::count()];
        $this->artisan('wealthdesk:test-data')->assertExitCode(0);
        $after = [User::count(), Client::count(), Position::count(), Deposit::count(), Withdrawal::count(), AuditEvent::count()];
        $this->assertSame($before, $after);
    }

    public function test_existing_admin_credentials_and_workspace_settings_are_preserved(): void
    {
        $admin = User::factory()->create([
            'name' => 'Existing Admin',
            'role' => 'admin',
            'password' => $this->temporaryPassword(),
        ]);
        $adminHash = $admin->password;
        $settings = WorkspaceSetting::create([
            'name' => 'Existing Workspace',
            'payment_name' => 'Existing Payee',
            'upi_id' => 'existing@example.com',
        ]);
        $clientPassword = $this->temporaryPassword();

        $this->artisan('wealthdesk:test-data')
            ->expectsQuestion('Shared test-client password (hidden input)', $clientPassword)
            ->assertExitCode(0);

        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame($adminHash, $admin->fresh()->password);
        $this->assertSame('Existing Admin', $admin->fresh()->name);
        $this->assertSame('Existing Workspace', $settings->fresh()->name);
        $this->assertSame('Existing Payee', $settings->fresh()->payment_name);
        $this->assertSame('existing@example.com', $settings->fresh()->upi_id);
    }

    public function test_reserved_identity_collision_aborts_without_changing_existing_records(): void
    {
        $admin = User::factory()->create([
            'name' => 'Existing Admin',
            'role' => 'admin',
            'password' => $this->temporaryPassword(),
        ]);
        $adminHash = $admin->password;
        $existingUser = User::factory()->create([
            'email' => 'wealthdesk.qa.client01@example.com',
            'role' => 'client',
            'password' => $this->temporaryPassword(),
        ]);
        $existingClient = Client::create([
            'user_id' => $existingUser->id,
            'code' => 'A00001',
            'phone' => '+1 202 555 0100',
            'risk' => 'Low',
            'investment' => 100_000,
            'details' => ['first_name' => 'Existing'],
            'sample' => false,
        ]);
        WorkspaceSetting::create(['name' => 'Existing Workspace']);
        $before = [User::count(), Client::count(), Position::count(), Deposit::count(), Withdrawal::count()];

        $this->artisan('wealthdesk:test-data')->assertExitCode(1);

        $this->assertSame($before, [User::count(), Client::count(), Position::count(), Deposit::count(), Withdrawal::count()]);
        $this->assertSame('Existing Admin', $admin->fresh()->name);
        $this->assertSame($adminHash, $admin->fresh()->password);
        $this->assertSame('A00001', $existingClient->fresh()->code);
        $this->assertFalse($existingClient->fresh()->sample);
        $this->assertSame('Existing Workspace', WorkspaceSetting::first()->name);
    }

    private function temporaryPassword(): string
    {
        return 'Qa'.Str::random(18).'9Z';
    }
}