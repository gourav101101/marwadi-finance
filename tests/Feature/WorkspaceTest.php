<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deposit;
use App\Models\Document;
use App\Models\Position;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Ledger;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): static
    {
        $this->flushSession();
        $this->actingAs($user);

        return $this;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'password' => 'AdminTest123!']);
    }

    private function client(string $name = 'Aarav Sharma', int $investment = 1000000): Client
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'client', 'password' => 'ClientTest123!']);

        return DB::transaction(fn () => Client::create([
            'user_id' => $user->id,
            'code' => 'MFSLT'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'phone' => '9000000000',
            'risk' => 'Medium',
            'investment' => $investment,
            'details' => ['first_name' => explode(' ', $name)[0], 'bank_name' => 'Test Bank'],
        ]));
    }

    private function positionData(Client $client): array
    {
        return ['client_id' => $client->id, 'category' => 'Equity', 'section' => 'Last trades', 'stock' => 'RELIANCE', 'date' => '2026-10-17T10:30', 'side' => 'Buy', 'quantity' => 10, 'entry_price' => '100', 'ltp' => '150', 'exit_price' => '150', 'pnl' => '500', 'state' => 'Closed'];
    }

    public function test_login_supports_email_and_client_id_without_external_auth(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $this->get('/')->assertRedirect('/login');
        $this->get('/admin')->assertNotFound();
        $this->get('/login')->assertOk()->assertSee('Email address or Client ID')->assertDontSee('Continue with ChatGPT');
        $this->post('/login', ['login' => $admin->email, 'password' => 'wrong-password'])->assertSessionHasErrors('login');
        $this->assertGuest();
        $this->post('/login', ['login' => $admin->email, 'password' => 'AdminTest123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['login' => strtolower($client->code), 'password' => 'ClientTest123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($client->user);
    }

    public function test_official_logo_is_used_on_login_and_admin_and_client_workspaces(): void
    {
        $logo = 'images/marwadi-finance-logo.jpg';
        $this->get('/login')->assertOk()->assertSee($logo)->assertSee('alt="MARWADI FINANCE"', false);

        $admin = $this->admin();
        $this->signIn($admin)->get('/')->assertOk()->assertSee($logo)->assertSee('alt="MARWADI FINANCE"', false);

        $client = $this->client();
        $this->signIn($client->user)->get('/')->assertOk()->assertSee($logo)->assertSee('alt="MARWADI FINANCE"', false);
    }

    public function test_header_account_menu_offers_csrf_protected_post_logout_for_admin_and_client(): void
    {
        $admin = $this->admin();
        $this->signIn($admin)->get('/')->assertOk()
            ->assertSee('aria-label="Account menu"', false)
            ->assertSee('Administrator')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('Sign out');

        $client = $this->client();
        $this->signIn($client->user)->get('/')->assertOk()
            ->assertSee('aria-label="Account menu"', false)
            ->assertSee('Client account')
            ->assertSee('Sign out');
    }

    public function test_client_cannot_access_administrator_actions_or_other_client_records(): void
    {
        $one = $this->client('Visible Client');
        $two = $this->client('Hidden Client');
        Position::create(collect($this->positionData($two))->except(['entry_price', 'ltp', 'exit_price', 'pnl'])->all() + ['entry_price' => 10000, 'ltp' => 15000, 'exit_price' => 15000, 'pnl' => 50000]);
        $this->signIn($one->user);
        $this->get('/')->assertOk()->assertSee('Visible')->assertDontSee('Hidden Client');
        $this->get('/admin/clients')->assertForbidden();
        $this->post('/admin/deposits', [])->assertForbidden();
        $this->get('/admin/clients/'.$two->id)->assertForbidden();
        $this->get('/positions?section=Last+trades')->assertOk()->assertDontSee('RELIANCE');
        $this->get('/export/clients')->assertOk()->assertDownload('marwadi-finance-clients.csv');
    }

    public function test_recent_activity_is_hidden_from_client_dashboard_but_remains_on_admin_dashboard(): void
    {
        $admin = $this->admin();
        $client = $this->client('Dashboard Client', 250000);

        $clientDashboard = $this->signIn($client->user)->get('/')->assertOk()
            ->assertSee('Portfolio balance')
            ->assertSee('Recent positions')
            ->assertSee(\App\Support\Format::short(app(Ledger::class)->balance($client)['available']));
        $clientDashboard->assertDontSee('Recent activity')
            ->assertDontSee('The latest in your workspace.');

        $adminDashboard = $this->signIn($admin)->get('/')->assertOk();
        $adminDashboard->assertSee('Recent activity')
            ->assertSee('The latest in your workspace.')
            ->assertSee('Your clients');
    }

    public function test_administrator_screens_render_and_client_can_be_created_and_edited(): void
    {
        $this->signIn($this->admin());
        foreach (['/', '/admin/clients', '/admin/clients/create', '/positions', '/admin/positions/create', '/transactions', '/admin/deposits/create', '/withdrawals/request', '/admin/settings', '/activity', '/funds', '/coming-soon/reports'] as $url) {
            $this->get($url)->assertOk()->assertSee('<main class="content">', false)->assertDontSee('@endsection');
        }
        $input = ['first_name' => 'Mira', 'surname' => 'Shah', 'email' => 'mira@example.com', 'code' => 'MFSL1001', 'phone' => '9000000001', 'risk' => 'Low', 'investment' => '10000.01', 'password' => 'ClientTest123!', 'pan' => 'ABCDE1234F', 'ifsc' => 'ABCD0123456', 'aadhaar' => '123456789012', 'bank_name' => 'Sample Bank'];
        $this->post('/admin/clients', $input)->assertSessionHasNoErrors();
        $client = Client::with('user')->firstOrFail();
        $this->assertSame('MFSL1001', $client->code);
        $this->assertSame(1000001, $client->investment);
        $this->assertTrue(Hash::check('ClientTest123!', $client->user->password));
        $this->assertSame('Sample Bank', $client->details['bank_name']);
        $this->get('/admin/clients/'.$client->id)->assertOk()->assertSee('Mira Shah');
        $this->get('/admin/clients/'.$client->id.'/edit')->assertOk();
        $this->put('/admin/clients/'.$client->id, array_replace($input, ['code' => 'MFSL1001-UPDATED', 'first_name' => 'Mira Updated', 'password' => '']))->assertSessionHasNoErrors();
        $this->assertSame('Mira Updated Shah', $client->user->fresh()->name);
        $this->assertSame('MFSL1001-UPDATED', $client->fresh()->code);
    }

    public function test_admin_manually_enters_unique_client_ids_that_are_searchable_and_usable_for_login(): void
    {
        $this->signIn($this->admin());
        $input = [
            'first_name' => 'Generated',
            'surname' => 'Client',
            'email' => 'generated.client@example.com',
            'code' => 'mfsl1002',
            'phone' => '9000000002',
            'risk' => 'Low',
            'investment' => '10000',
            'password' => 'ClientTest123!',
        ];

        $this->get('/admin/clients/create')->assertOk()
            ->assertSee('name="code"', false)
            ->assertSee('Client ID', false)
            ->assertDontSee('User ID');
        $sequenceBeforeCreate = DB::table('client_id_sequences')->value('next_number');
        $this->post('/admin/clients', array_replace($input, ['code' => '']))->assertSessionHasErrors('code');
        $this->post('/admin/clients', array_replace($input, ['code' => 'bad id']))->assertSessionHasErrors('code');
        $this->post('/admin/clients', $input)->assertSessionHasNoErrors();
        $this->assertSame($sequenceBeforeCreate, DB::table('client_id_sequences')->value('next_number'));
        $first = Client::whereHas('user', fn ($query) => $query->where('email', $input['email']))->firstOrFail();
        $this->assertSame('MFSL1002', $first->code);
        $this->assertDatabaseHas('clients', ['id' => $first->id, 'code' => 'MFSL1002']);

        $this->post('/admin/clients', array_replace($input, [
            'first_name' => 'Duplicate',
            'email' => 'duplicate.client@example.com',
            'code' => 'mfsl1002',
            'phone' => '9000000003',
        ]))->assertSessionHasErrors(['code' => 'This Client ID is already in use. Enter a different Client ID.']);
        $this->assertDatabaseMissing('users', ['email' => 'duplicate.client@example.com']);

        $secondInput = array_replace($input, [
            'first_name' => 'Another',
            'email' => 'another.generated.client@example.com',
            'code' => 'MFSL1003',
        ]);
        $this->post('/admin/clients', $secondInput)->assertSessionHasNoErrors();
        $this->assertSame($sequenceBeforeCreate, DB::table('client_id_sequences')->value('next_number'));
        $second = Client::whereHas('user', fn ($query) => $query->where('email', $secondInput['email']))->firstOrFail();
        $this->assertSame('MFSL1003', $second->code);
        $this->assertNotSame($first->code, $second->code);
        $this->get('/admin/clients?q=MFSL1002')->assertOk()->assertSee('Showing 1–1 of 1 clients')->assertSee('Generated Client')->assertDontSee('Another Client');
        $this->get('/admin/clients/'.$first->id.'/edit')->assertOk()
            ->assertSee('name="code"', false)
            ->assertSee('value="MFSL1002"', false);
        $deposit = Deposit::create(['client_id' => $first->id, 'date' => now(), 'amount' => 25000, 'processed_by' => 'QA', 'reference' => 'MANUAL-ID-HISTORY', 'status' => 'Completed']);
        $position = Position::create($this->positionData($first));
        $balanceBeforeIdEdit = app(Ledger::class)->balance($first)['available'];
        $this->put('/admin/clients/'.$first->id, array_replace($input, ['code' => 'MFSL1003', 'password' => '']))
            ->assertSessionHasErrors(['code' => 'This Client ID is already in use. Enter a different Client ID.']);
        $this->assertSame('MFSL1002', $first->fresh()->code);
        $this->put('/admin/clients/'.$first->id, array_replace($input, ['code' => 'MFSL2002', 'password' => '']))->assertSessionHasNoErrors();
        $this->assertSame('MFSL2002', $first->fresh()->code);
        $this->assertDatabaseHas('clients', ['id' => $second->id, 'code' => 'MFSL1003']);
        $this->assertDatabaseHas('deposits', ['id' => $deposit->id, 'client_id' => $first->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('positions', ['id' => $position->id, 'client_id' => $first->id, 'deleted_at' => null]);
        $this->assertSame($balanceBeforeIdEdit, app(Ledger::class)->balance($first->fresh())['available']);

        $this->post('/logout');
        $this->post('/login', ['login' => strtolower($first->fresh()->code), 'password' => 'ClientTest123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($first->user);
        $this->get('/profile')->assertOk()->assertSee('MFSL2002');
    }

    public function test_demo_clients_use_explicit_mfsl_ids(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $this->assertSame(
            ['MFSLDEMO01', 'MFSLDEMO02', 'MFSLDEMO03', 'MFSLDEMO04', 'MFSLDEMO05', 'MFSLDEMO06'],
            Client::orderBy('id')->pluck('code')->all()
        );
    }

    public function test_completed_deposits_and_closed_trades_affect_balance_but_pending_deposits_do_not(): void
    {
        $client = $this->client();
        $this->signIn($this->admin());
        foreach (['Completed' => '5000', 'Pending' => '2000'] as $status => $amount) {
            $this->post('/admin/deposits', ['client_id' => $client->id, 'date' => '2026-10-17T10:30', 'amount' => $amount, 'processed_by' => 'QA', 'reference' => 'REF-'.$status, 'status' => $status])->assertSessionHasNoErrors();
        }
        $this->post('/admin/positions', $this->positionData($client))->assertSessionHasNoErrors();
        $balance = app(Ledger::class)->balance($client);
        $this->assertSame(1550000, $balance['available']);
        $this->assertSame(500000, $balance['deposits']);
        $this->assertSame(50000, $balance['realized']);
        $this->post('/admin/positions', array_replace($this->positionData($client), ['category' => 'Forex & Currency', 'section' => 'Orders']))->assertSessionHasErrors('section');
    }

    public function test_admin_entered_profit_and_loss_are_visible_to_the_assigned_client_in_every_market(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $other = $this->client('Other Client');

        foreach ([...Ledger::CATEGORIES, Ledger::LEGACY_CATEGORY] as $category) {
            foreach (['1234.56', '-789.12'] as $amount) {
                $data = array_replace($this->positionData($client), [
                    'category' => $category,
                    'section' => 'Current positions',
                    'state' => 'Active',
                    'stock' => 'CLIENT-PNL-CHECK',
                    'exit_price' => '',
                    'pnl' => $amount,
                ]);
                $this->signIn($admin);
                if ($category === Ledger::LEGACY_CATEGORY) {
                    // Legacy entries can be edited, but cannot be newly added through the form.
                    $position = Position::create(array_replace($data, ['pnl' => 0]));
                    $this->put('/admin/positions/'.$position->id, $data)->assertSessionHasNoErrors();
                } else {
                    $this->post('/admin/positions', $data)->assertSessionHasNoErrors();
                    $position = Position::latest('id')->firstOrFail();
                }

                $url = '/positions?'.http_build_query(['category' => $category, 'section' => 'Current positions']);
                $this->signIn($client->user);
                $this->get($url)->assertOk()
                    ->assertSee('PROFIT / LOSS')
                    ->assertSee('CLIENT-PNL-CHECK')
                    ->assertSee(Format::money(Ledger::paise($amount)));
                $this->get('/')->assertOk()->assertSee(Format::money(Ledger::paise($amount)));
                $this->assertSame($client->investment, app(Ledger::class)->balance($client)['available']);

                $this->signIn($other->user);
                $this->get($url)->assertOk()->assertDontSee('CLIENT-PNL-CHECK');
                $position->delete();
            }
        }
    }

    public function test_closed_position_profit_loss_updates_funds_once_and_tracks_pnl_changes(): void
    {
        $client = $this->client(investment: 5000000);
        $this->signIn($this->admin());
        $baseBalance = app(Ledger::class)->balance($client)['available'];

        $this->post('/admin/positions', array_replace($this->positionData($client), ['pnl' => '0']))->assertSessionHasNoErrors();
        $position = Position::firstOrFail();
        $this->assertSame($baseBalance, app(Ledger::class)->balance($client)['available']);
        $this->assertSame(0, app(Ledger::class)->balance($client)['realized']);

        $this->put('/admin/positions/'.$position->id, array_replace($this->positionData($client), ['pnl' => '5000']))->assertSessionHasNoErrors();
        $this->assertSame($baseBalance + 500000, app(Ledger::class)->balance($client)['available']);
        $this->assertSame(500000, app(Ledger::class)->balance($client)['realized']);

        $this->put('/admin/positions/'.$position->id, array_replace($this->positionData($client), ['pnl' => '5000']))->assertSessionHasNoErrors();
        $this->assertSame($baseBalance + 500000, app(Ledger::class)->balance($client)['available']);

        $this->put('/admin/positions/'.$position->id, array_replace($this->positionData($client), ['pnl' => '-2000']))->assertSessionHasNoErrors();
        $this->assertSame($baseBalance - 200000, app(Ledger::class)->balance($client)['available']);
        $this->assertSame(-200000, app(Ledger::class)->balance($client)['realized']);

        $this->put('/admin/positions/'.$position->id, array_replace($this->positionData($client), ['pnl' => '3000']))->assertSessionHasNoErrors();
        $this->assertSame($baseBalance + 300000, app(Ledger::class)->balance($client)['available']);
        $this->assertSame(300000, app(Ledger::class)->balance($client)['realized']);
    }

    public function test_marking_a_current_position_closed_realizes_its_pnl_and_keeps_panel_balances_consistent(): void
    {
        $client = $this->client(investment: 5000000);
        $this->signIn($this->admin());
        $baseBalance = app(Ledger::class)->balance($client)['available'];
        $positionData = array_replace($this->positionData($client), [
            'section' => 'Current positions',
            'state' => 'Closed',
            'pnl' => '5000',
        ]);

        $this->post('/admin/positions', $positionData)->assertSessionHasNoErrors();
        $position = Position::firstOrFail();
        $this->assertSame('Last trades', $position->section);
        $this->assertSame('Closed', $position->state);
        $this->assertSame($baseBalance + 500000, app(Ledger::class)->balance($client)['available']);

        $this->get('/')->assertOk()->assertSee(\App\Support\Format::short($baseBalance + 500000));
        $this->get('/funds')->assertOk()->assertSee(\App\Support\Format::money($baseBalance + 500000));
        $this->get('/funds')->assertOk()->assertSee(\App\Support\Format::money($baseBalance + 500000));
        $this->assertSame($baseBalance + 500000, app(Ledger::class)->balance($client)['available']);

        $this->put('/admin/positions/'.$position->id, array_replace($positionData, ['pnl' => '-2000']))->assertSessionHasNoErrors();
        $this->assertSame($baseBalance - 200000, app(Ledger::class)->balance($client)['available']);
        $this->put('/admin/positions/'.$position->id, array_replace($positionData, ['pnl' => '0']))->assertSessionHasNoErrors();
        $this->assertSame($baseBalance, app(Ledger::class)->balance($client)['available']);
    }

    public function test_legacy_closed_current_position_is_realized_and_reopening_removes_its_pnl(): void
    {
        $client = $this->client(investment: 5000000);
        $this->signIn($this->admin());
        $legacyClosed = Position::create(array_replace($this->positionData($client), [
            'section' => 'Current positions',
            'state' => 'Closed',
            'pnl' => 500000,
        ]));
        $this->assertSame(5500000, app(Ledger::class)->balance($client)['available']);

        $this->put('/admin/positions/'.$legacyClosed->id, array_replace($this->positionData($client), [
            'section' => 'Current positions',
            'state' => 'Active',
            'exit_price' => '',
            'pnl' => 500000,
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Current positions', $legacyClosed->fresh()->section);
        $this->assertSame('Active', $legacyClosed->fresh()->state);
        $this->assertSame(5000000, app(Ledger::class)->balance($client)['available']);
    }

    public function test_legacy_closed_positions_are_not_counted_as_open_on_either_dashboard(): void
    {
        $client = $this->client();
        Position::create(array_replace($this->positionData($client), [
            'stock' => 'LEGACY-CLOSED', 'section' => 'Current positions', 'state' => 'Closed', 'pnl' => 12500,
        ]));
        foreach ([$this->admin(), $client->user] as $user) {
            $this->signIn($user);
            $this->get('/')->assertOk()->assertSee('0 open positions')
                ->assertSee('Unrealized P&amp;L</span><strong class="positive">'.Format::money(0), false)
                ->assertDontSee('LEGACY-CLOSED');
        }
        $this->assertSame(12500, app(Ledger::class)->balance($client)['realized']);
    }

    public function test_dashboard_pnl_summary_tracks_open_edits_and_closure_without_double_counting(): void
    {
        $admin = $this->admin();
        $client = $this->client();
        $other = $this->client('Other Client');
        Position::create(array_replace($this->positionData($other), ['pnl' => 999999]));
        $data = array_replace($this->positionData($client), [
            'section' => 'Current positions', 'state' => 'Active', 'pnl' => '500',
        ]);
        $this->signIn($admin)->post('/admin/positions', $data)->assertSessionHasNoErrors();
        $position = $client->positions()->firstOrFail();

        $assertSummary = function (int $open, int $total) use ($client) {
            $this->signIn($client->user);
            $html = $this->get('/')->assertOk()->getContent();
            foreach (['Open profit / loss' => $open, 'Total profit / loss' => $total] as $label => $amount) {
                $value = ($amount > 0 ? '+' : '').Format::money($amount);
                $color = $amount < 0 ? 'negative' : 'positive';
                $this->assertMatchesRegularExpression('~'.preg_quote($label, '~').'</span>.*?</div><h2 class="'.$color.'">'.preg_quote($value, '~').'</h2>~s', $html);
            }
        };

        $assertSummary(50000, 50000);
        $this->assertSame($client->investment, app(Ledger::class)->balance($client)['available']);
        $this->signIn($admin)->put('/admin/positions/'.$position->id, array_replace($data, ['pnl' => '-200', 'state' => 'Hold']))->assertSessionHasNoErrors();
        $assertSummary(-20000, -20000);
        $this->assertSame($client->investment, app(Ledger::class)->balance($client)['available']);
        $this->signIn($admin)->put('/admin/positions/'.$position->id, array_replace($data, ['pnl' => '-200', 'state' => 'Closed']))->assertSessionHasNoErrors();
        $assertSummary(0, -20000);
        $this->assertSame($client->investment - 20000, app(Ledger::class)->balance($client)['available']);

        $this->signIn($admin)->post('/admin/positions', array_replace($data, ['category' => 'Options', 'pnl' => '75']))->assertSessionHasNoErrors();
        $assertSummary(7500, -12500);
    }

    public function test_open_position_unrealized_profit_does_not_change_available_funds_until_closed(): void
    {
        $client = $this->client();
        $this->signIn($this->admin());
        $openPosition = array_replace($this->positionData($client), [
            'section' => 'Current positions',
            'exit_price' => '',
            'state' => 'Active',
            'pnl' => '5000',
        ]);

        $this->post('/admin/positions', $openPosition)->assertSessionHasNoErrors();
        $position = Position::firstOrFail();
        $this->assertSame($client->investment, app(Ledger::class)->balance($client)['available']);
        $this->assertSame(0, app(Ledger::class)->balance($client)['realized']);

        $this->put('/admin/positions/'.$position->id, array_replace($this->positionData($client), ['pnl' => '5000']))->assertSessionHasNoErrors();
        $this->assertSame($client->investment + 500000, app(Ledger::class)->balance($client)['available']);
        $this->assertSame(500000, app(Ledger::class)->balance($client)['realized']);
    }

    public function test_client_funds_and_dashboard_show_realized_balance_after_withdrawal_reservations(): void
    {
        $client = $this->client(investment: 5000000);
        Deposit::create(['client_id' => $client->id, 'date' => now(), 'amount' => 100000, 'processed_by' => 'QA', 'reference' => 'BALANCE-CHECK', 'status' => 'Completed']);
        Position::create(array_replace($this->positionData($client), ['pnl' => 500000]));
        Withdrawal::create(['client_id' => $client->id, 'amount' => 50000, 'status' => 'Pending']);

        $balance = app(Ledger::class)->balance($client);
        $this->assertSame(5600000, $balance['balance']);
        $this->assertSame(50000, $balance['pending']);
        $this->assertSame(5550000, $balance['available']);

        $this->signIn($client->user);
        $this->get('/')->assertOk()->assertSee(\App\Support\Format::short($balance['balance']));
        $this->get('/funds')->assertOk()
            ->assertSee(\App\Support\Format::money($balance['available']))
            ->assertSee(\App\Support\Format::money($balance['balance']))
            ->assertSee(\App\Support\Format::money($balance['pending']));
    }

    public function test_futures_and_options_positions_are_separate_and_share_realized_balance_once(): void
    {
        $client = $this->client(investment: 10000000);
        $this->signIn($this->admin());

        $this->get('/admin/positions/create')
            ->assertOk()
            ->assertSee('value="Futures"', false)
            ->assertSee('value="Options"', false);
        foreach (['Futures' => '100', 'Options' => '-25.50'] as $category => $pnl) {
            $this->post('/admin/positions', array_replace($this->positionData($client), [
                'category' => $category,
                'stock' => $category.' QA',
                'pnl' => $pnl,
            ]))->assertSessionHasNoErrors();
        }

        $futures = Position::where('category', 'Futures')->firstOrFail();
        $options = Position::where('category', 'Options')->firstOrFail();
        $this->assertSame('Futures', $futures->category);
        $this->assertSame('Options', $options->category);
        $this->assertSame(7450, app(Ledger::class)->balance($client)['realized']);
        $this->assertSame($client->investment + 7450, app(Ledger::class)->balance($client)['available']);
        foreach (['Futures', 'Options'] as $category) {
            Position::create(array_replace($this->positionData($client), [
                'category' => $category,
                'section' => 'Current positions',
                'stock' => $category.' OPEN QA',
                'exit_price' => null,
                'state' => 'Active',
            ]));
        }
        $this->get('/')
            ->assertOk()
            ->assertSee('Futures')
            ->assertSee('Options')
            ->assertSee('₹74.50');

        $this->get('/positions?category=Futures&section=Last+trades')
            ->assertOk()
            ->assertSee('Futures QA')
            ->assertDontSee('Options QA');
        $this->get('/positions?category=Options&section=Last+trades')
            ->assertOk()
            ->assertSee('Options QA')
            ->assertDontSee('Futures QA');
        $this->put('/admin/positions/'.$futures->id, array_replace($this->positionData($client), [
            'category' => 'Options',
            'stock' => 'Reclassified QA',
            'pnl' => '100',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Options', $futures->fresh()->category);
        $this->assertSame(7450, app(Ledger::class)->balance($client)['realized']);
        $this->assertSame($client->investment + 7450, app(Ledger::class)->balance($client)['available']);
        $this->get('/positions?category=Futures&section=Last+trades')
            ->assertOk()
            ->assertDontSee('Reclassified QA');
        $this->get('/positions?category=Options&section=Last+trades')
            ->assertOk()
            ->assertSee('Reclassified QA')
            ->assertSee('Options QA');
    }

    public function test_legacy_futures_and_options_records_remain_filterable_and_can_be_reclassified(): void
    {
        $client = $this->client(investment: 10000000);
        $legacy = Position::create(array_replace($this->positionData($client), [
            'category' => Ledger::LEGACY_CATEGORY,
            'stock' => 'Legacy QA',
            'entry_price' => 10000,
            'ltp' => 15000,
            'exit_price' => 15000,
            'pnl' => 25000,
        ]));
        $this->signIn($this->admin());

        $this->get('/positions?category=Futures+%26+Options&section=Last+trades')
            ->assertOk()
            ->assertSee('Futures & Options (legacy)')
            ->assertSee('Legacy QA');
        $this->get('/admin/positions/'.$legacy->id.'/edit')
            ->assertOk()
            ->assertSee('Futures & Options (legacy; reclassify if known)');
        $before = app(Ledger::class)->balance($client);
        $this->put('/admin/positions/'.$legacy->id, array_replace($this->positionData($client), [
            'category' => 'Futures',
            'stock' => 'Reclassified Legacy QA',
            'pnl' => '250',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Futures', $legacy->fresh()->category);
        $this->assertSame($before['realized'], app(Ledger::class)->balance($client)['realized']);
        $this->assertSame($before['available'], app(Ledger::class)->balance($client)['available']);
        $this->get('/positions?category=Futures&section=Last+trades')
            ->assertOk()
            ->assertSee('Reclassified Legacy QA');
    }

    public function test_expiry_dates_are_optional_for_relevant_categories_and_filters_do_not_change_ledger(): void
    {
        $client = $this->client(investment: 10000000);
        $today = now()->timezone('Asia/Kolkata');
        $upcomingDate = $today->copy()->addDays(3)->toDateString();
        $pastDate = $today->copy()->subDays(3)->toDateString();
        $this->signIn($this->admin());

        $this->get('/admin/positions/create?category=Futures')
            ->assertOk()
            ->assertSee('name="expiry_date"', false);
        $this->get('/admin/positions/create?category=Options')
            ->assertOk()
            ->assertSee('name="expiry_date"', false);
        $this->get('/admin/positions/create?category=Equity')
            ->assertOk()
            ->assertSee('data-expiry-field', false)
            ->assertSee('hidden', false);

        $positionData = fn (string $category, string $stock, ?string $expiryDate) => array_replace($this->positionData($client), [
            'category' => $category,
            'section' => 'Current positions',
            'stock' => $stock,
            'state' => 'Active',
            'exit_price' => '',
            'pnl' => '125',
            'expiry_date' => $expiryDate,
        ]);
        $this->post('/admin/positions', $positionData('Futures', 'Expiry Futures QA', $upcomingDate))->assertSessionHasNoErrors();
        $this->post('/admin/positions', $positionData('Options', 'Expiry Options QA', $pastDate))->assertSessionHasNoErrors();
        Position::create(array_replace(collect($this->positionData($client))->except(['entry_price', 'ltp', 'exit_price', 'pnl'])
            ->all(), [
                'category' => Ledger::LEGACY_CATEGORY,
                'section' => 'Current positions',
                'stock' => 'Expiry Legacy QA',
                'entry_price' => 10000,
                'ltp' => 15000,
                'exit_price' => null,
                'pnl' => 12500,
                'expiry_date' => null,
                'state' => 'Active',
            ]));
        $this->post('/admin/positions', $positionData('Equity', 'Expiry Equity QA', $upcomingDate))->assertSessionHasNoErrors();

        $futures = Position::where('stock', 'Expiry Futures QA')->firstOrFail();
        $options = Position::where('stock', 'Expiry Options QA')->firstOrFail();
        $legacy = Position::where('stock', 'Expiry Legacy QA')->firstOrFail();
        $equity = Position::where('stock', 'Expiry Equity QA')->firstOrFail();
        $this->assertSame($upcomingDate, $futures->expiry_date->format('Y-m-d'));
        $this->assertSame($pastDate, $options->expiry_date->format('Y-m-d'));
        $this->assertNull($legacy->expiry_date);
        $this->assertNull($equity->expiry_date);
        $this->assertSame('Active', $options->state);
        $export = $this->get('/export/positions')
            ->assertOk()
            ->assertDownload('marwadi-finance-positions.csv');
        $this->assertStringContainsString('Expiry Date', $export->streamedContent());
        $this->assertStringContainsString($upcomingDate, $export->streamedContent());

        $before = app(Ledger::class)->balance($client);
        $this->get('/positions?category=Futures&section=Current+positions&expiry=upcoming')
            ->assertOk()
            ->assertSee('Expiry Futures QA')
            ->assertSee($today->copy()->addDays(3)->format('d M Y'))
            ->assertDontSee('Expiry Options QA');
        $this->get('/positions?category=Options&section=Current+positions&expiry=expired')
            ->assertOk()
            ->assertSee('Expiry Options QA')
            ->assertSee('Expiry passed · Active');
        $this->get('/positions?category=Futures+%26+Options&section=Current+positions&expiry=all')
            ->assertOk()
            ->assertSee('Expiry Legacy QA')
            ->assertSee('—');

        $this->put('/admin/positions/'.$futures->id, array_replace(
            $positionData('Futures', 'Expiry Futures QA', $upcomingDate),
            ['expiry_date' => $pastDate]
        ))->assertSessionHasNoErrors();
        $optionsEditExpiry = $today->copy()->addDays(5)->toDateString();
        $this->put('/admin/positions/'.$options->id, array_replace(
            $positionData('Options', 'Expiry Options QA', $pastDate),
            ['expiry_date' => $optionsEditExpiry]
        ))->assertSessionHasNoErrors();
        $legacyEditExpiry = $today->copy()->subDays(10)->toDateString();
        $this->put('/admin/positions/'.$legacy->id, array_replace($this->positionData($client), [
            'category' => Ledger::LEGACY_CATEGORY,
            'section' => 'Current positions',
            'stock' => 'Expiry Legacy QA',
            'state' => 'Active',
            'exit_price' => '',
            'pnl' => '125',
            'expiry_date' => $legacyEditExpiry,
        ]))->assertSessionHasNoErrors();
        $this->assertSame($pastDate, $futures->fresh()->expiry_date->format('Y-m-d'));
        $this->assertSame($optionsEditExpiry, $options->fresh()->expiry_date->format('Y-m-d'));
        $this->assertSame($legacyEditExpiry, $legacy->fresh()->expiry_date->format('Y-m-d'));
        $this->assertSame($before['balance'], app(Ledger::class)->balance($client)['balance']);
        $this->assertSame($before['available'], app(Ledger::class)->balance($client)['available']);
        $this->assertSame($before['realized'], app(Ledger::class)->balance($client)['realized']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Futures &amp; Options expiry watch', false)
            ->assertSee('Expiry Futures QA')
            ->assertSee('Expiry passed · Active');
    }

    public function test_admin_and_client_panels_render_current_ledger_balances_after_refresh(): void
    {
        $client = $this->client('Ledger Client', 5000000);
        $otherClient = $this->client('Unrelated Client', 8000000);
        $completedDeposit = Deposit::create([
            'client_id' => $client->id,
            'date' => now(),
            'amount' => 1000000,
            'processed_by' => 'QA',
            'reference' => 'E2E-COMPLETED',
            'status' => 'Completed',
        ]);
        $pendingDeposit = Deposit::create([
            'client_id' => $client->id,
            'date' => now(),
            'amount' => 400000,
            'processed_by' => 'QA',
            'reference' => 'E2E-PENDING',
            'status' => 'Pending',
        ]);
        $approvedWithdrawal = Withdrawal::create([
            'client_id' => $client->id,
            'amount' => 300000,
            'status' => 'Approved',
        ]);
        $pendingWithdrawal = Withdrawal::create([
            'client_id' => $client->id,
            'amount' => 200000,
            'status' => 'Pending',
        ]);
        $this->signIn($this->admin());
        $this->post('/admin/positions', array_replace($this->positionData($client), ['pnl' => '5000']))->assertSessionHasNoErrors();

        $ledger = app(Ledger::class);
        $clientBalance = $ledger->balance($client);
        $otherClientBalance = $ledger->balance($otherClient);
        $this->assertSame(6200000, $clientBalance['balance']);
        $this->assertSame(6000000, $clientBalance['available']);
        $this->assertSame(8000000, $otherClientBalance['balance']);
        $this->assertSame(1000000, $clientBalance['deposits']);
        $this->assertSame(500000, $clientBalance['realized']);
        $this->assertSame(300000, $clientBalance['approved']);
        $this->assertSame(200000, $clientBalance['pending']);

        $adminBalance = $clientBalance['balance'] + $otherClientBalance['balance'];
        $adminAvailable = $clientBalance['available'] + $otherClientBalance['available'];
        $this->get('/')
            ->assertOk()
            ->assertSee(\App\Support\Format::short($adminBalance));
        $this->get('/funds')
            ->assertOk()
            ->assertSee(\App\Support\Format::money($adminAvailable))
            ->assertSee(\App\Support\Format::money($adminBalance));
        $this->get('/')->assertOk()->assertSee(\App\Support\Format::short($adminBalance));

        $this->signIn($client->user);
        foreach (['/', '/funds'] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertSee(\App\Support\Format::short($clientBalance['balance']));
        }
        $this->get('/')
            ->assertOk()
            ->assertSee(\App\Support\Format::short($clientBalance['balance']))
            ->assertSee(\App\Support\Format::short($clientBalance['available']))
            ->assertDontSee(\App\Support\Format::short($otherClientBalance['balance']));
        $this->get('/funds')
            ->assertOk()
            ->assertSee(\App\Support\Format::money($clientBalance['available']))
            ->assertSee(\App\Support\Format::money($clientBalance['balance']))
            ->assertSee(\App\Support\Format::money($clientBalance['pending']));

        $this->assertDatabaseHas('deposits', ['id' => $completedDeposit->id, 'status' => 'Completed', 'deleted_at' => null]);
        $this->assertDatabaseHas('deposits', ['id' => $pendingDeposit->id, 'status' => 'Pending', 'deleted_at' => null]);
        $this->assertDatabaseHas('withdrawals', ['id' => $approvedWithdrawal->id, 'status' => 'Approved']);
        $this->assertDatabaseHas('withdrawals', ['id' => $pendingWithdrawal->id, 'status' => 'Pending']);
        $this->assertSame(1, Position::where('client_id', $client->id)->count());
        $this->assertSame(0, Position::where('client_id', $otherClient->id)->count());
    }

    public function test_withdrawals_reserve_funds_ignore_forged_client_and_reject_overdrafts(): void
    {
        $one = $this->client();
        $two = $this->client('Other Client');
        $this->signIn($one->user);
        $this->post('/withdrawals', ['client_id' => $two->id, 'amount' => '9000', 'note' => 'Test'])->assertSessionHasNoErrors();
        $withdrawal = Withdrawal::firstOrFail();
        $this->assertSame($one->id, $withdrawal->client_id);
        $this->assertSame('Pending', $withdrawal->status);
        $this->assertSame(100000, app(Ledger::class)->balance($one)['available']);
        $this->post('/withdrawals', ['amount' => '1000.01'])->assertSessionHasErrors('amount');
        $this->assertSame(1, Withdrawal::count());
        $this->post('/withdrawals', ['amount' => '1000'])->assertSessionHasNoErrors();
        $this->post('/withdrawals', ['amount' => '0.01'])->assertSessionHasErrors('amount');
        $this->assertSame(0, app(Ledger::class)->balance($one)['available']);
    }

    public function test_withdrawal_decisions_are_admin_only_and_cannot_be_reversed(): void
    {
        $client = $this->client();
        $withdrawal = Withdrawal::create(['client_id' => $client->id, 'amount' => 400000, 'status' => 'Pending']);
        $this->signIn($client->user)->post('/admin/withdrawals/'.$withdrawal->id.'/decision', ['status' => 'Approved'])->assertForbidden();
        $admin = $this->admin();
        $this->signIn($admin)->post('/admin/withdrawals/'.$withdrawal->id.'/decision', ['status' => 'Approved'])->assertSessionHasNoErrors();
        $this->assertSame('Approved', $withdrawal->fresh()->status);
        $this->assertSame($admin->id, $withdrawal->fresh()->decided_by);
        $this->post('/admin/withdrawals/'.$withdrawal->id.'/decision', ['status' => 'Declined'])->assertSessionHasErrors('withdrawal');
        $this->assertSame(600000, app(Ledger::class)->balance($client)['available']);
        $second = Withdrawal::create(['client_id' => $client->id, 'amount' => 100000, 'status' => 'Pending']);
        $this->post('/admin/withdrawals/'.$second->id.'/decision', ['status' => 'Declined'])->assertSessionHasNoErrors();
        $this->assertSame(600000, app(Ledger::class)->balance($client)['available']);
    }

    public function test_deposit_removal_and_negative_trade_edit_cannot_break_reserved_funds(): void
    {
        $client = $this->client();
        $deposit = Deposit::create(['client_id' => $client->id, 'date' => now(), 'amount' => 500000, 'processed_by' => 'QA', 'reference' => 'RESERVE-1', 'status' => 'Completed']);
        Withdrawal::create(['client_id' => $client->id, 'amount' => 1400000, 'status' => 'Pending']);
        $this->signIn($this->admin())->delete('/admin/deposits/'.$deposit->id)->assertSessionHasErrors('amount');
        $this->assertDatabaseHas('deposits', ['id' => $deposit->id, 'deleted_at' => null]);
        $this->post('/admin/positions', array_replace($this->positionData($client), ['pnl' => '-2000']))->assertSessionHasErrors('amount');
        $this->assertSame(0, Position::count());
        $this->assertSame(100000, app(Ledger::class)->balance($client)['available']);
    }

    public function test_hold_resume_remove_and_duplicate_transaction_reference(): void
    {
        $client = $this->client();
        $this->signIn($this->admin());
        $this->post('/admin/positions', array_replace($this->positionData($client), ['section' => 'Current positions', 'state' => 'Active']))->assertSessionHasNoErrors();
        $position = Position::firstOrFail();
        $this->post('/admin/positions/'.$position->id.'/action', ['action' => 'hold'])->assertSessionHasNoErrors();
        $this->assertSame('Hold', $position->fresh()->state);
        $this->post('/admin/positions/'.$position->id.'/action', ['action' => 'hold'])->assertSessionHasNoErrors();
        $this->assertSame('Active', $position->fresh()->state);
        $this->post('/admin/positions/'.$position->id.'/action', ['action' => 'remove'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($position);
        $deposit = ['client_id' => $client->id, 'date' => '2026-10-17T10:30', 'amount' => '100', 'processed_by' => 'QA', 'reference' => 'UNIQUE-REF', 'status' => 'Completed'];
        $this->post('/admin/deposits', $deposit)->assertSessionHasNoErrors();
        $this->post('/admin/deposits', $deposit)->assertSessionHasErrors('reference');
    }

    public function test_password_change_is_hashed_audited_and_visible_to_admin(): void
    {
        $client = $this->client();
        $this->signIn($client->user);
        $this->get('/profile')->assertOk()->assertSee('Change password');
        $this->post('/profile/password', ['current_password' => 'wrong', 'password' => 'NewClient123!', 'password_confirmation' => 'NewClient123!'])->assertSessionHasErrors('current_password');
        $this->post('/profile/password', ['current_password' => 'ClientTest123!', 'password' => 'NewClient123!', 'password_confirmation' => 'NewClient123!'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewClient123!', $client->user->fresh()->password));
        $this->assertNotNull($client->user->fresh()->password_changed_at);
        $this->assertDatabaseHas('audit_events', ['client_id' => $client->id, 'action' => 'password']);
        $this->signIn($this->admin())->get('/admin/clients')->assertOk()->assertSee('Password changed')->assertDontSee('NewClient123!');
    }

    public function test_documents_are_private_and_clients_can_only_change_their_own_photo(): void
    {
        Storage::fake('local');
        $one = $this->client();
        $two = $this->client();
        $this->signIn($one->user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('photo.png')])->assertSessionHasNoErrors();
        $doc = Document::firstOrFail();
        Storage::disk('local')->assertExists($doc->path);
        $this->get('/documents/'.$doc->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->signIn($two->user)->get('/documents/'.$doc->id)->assertForbidden();
        $this->signIn($one->user)->post('/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])->assertSessionHasErrors('photo');
        $this->signIn($this->admin())->get('/documents/'.$doc->id)->assertOk();
    }

    public function test_admin_can_upload_and_replace_client_documents_including_pdf(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $client = $this->client();
        $data = ['code' => $client->code, 'first_name' => 'Aarav', 'email' => $client->user->email,
            'phone' => $client->phone, 'risk' => $client->risk, 'investment' => '10000'];
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $this->signIn($admin)->put('/admin/clients/'.$client->id, $data + [
            'photo' => UploadedFile::fake()->image('photo.jpg')->size(3072),
            'signature' => UploadedFile::fake()->image('signature.png'),
            'aadhaar_document' => UploadedFile::fake()->createWithContent('aadhaar.pdf', $pdf),
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $client->documents()->count());
        $original = $client->documents()->where('kind', 'aadhaar')->firstOrFail();
        $this->signIn($client->user)->get('/documents/'.$original->id)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get('/profile')->assertOk()->assertSee(route('documents.show', $original), false);

        $this->signIn($admin)->put('/admin/clients/'.$client->id, $data + [
            'aadhaar_document' => UploadedFile::fake()->image('replacement.png'),
        ])->assertSessionHasNoErrors();
        $replacement = $client->documents()->where('kind', 'aadhaar')->firstOrFail();
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(3, $client->documents()->count());
        Storage::disk('local')->assertMissing($original->path);
        Storage::disk('local')->assertExists($replacement->path);
        $this->signIn($client->user)->get('/documents/'.$replacement->id)->assertOk();
        $this->get('/documents/'.$original->id)->assertNotFound();
    }

    public function test_invalid_and_oversized_uploads_preserve_the_previous_document(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $this->signIn($client->user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('original.png')])->assertSessionHasNoErrors();
        $original = $client->documents()->firstOrFail();
        $disguised = UploadedFile::fake()->createWithContent('disguised.jpg', '<?php echo "not an image";');
        foreach ([
            UploadedFile::fake()->image('large.png')->size(5121),
            new UploadedFile($disguised->getPathname(), 'disguised.jpg', 'image/jpeg', null, true),
            new UploadedFile(__FILE__, 'failed.png', 'image/png', UPLOAD_ERR_INI_SIZE, true),
        ] as $file) {
            $this->post('/profile/photo', ['photo' => $file])->assertSessionHasErrors('photo');
            $this->assertSame($original->id, $client->documents()->sole()->id);
            Storage::disk('local')->assertExists($original->path);
        }
    }

    public function test_storage_write_failure_is_actionable_and_preserves_the_previous_document(): void
    {
        $disk = Storage::fake('local');
        $client = $this->client();
        $this->signIn($client->user)->post('/profile/photo', ['photo' => UploadedFile::fake()->image('original.png')])->assertSessionHasNoErrors();
        $original = $client->documents()->sole();
        $failedDisk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $failedDisk->shouldReceive('putFileAs')->once()->andThrow(\League\Flysystem\UnableToWriteFile::atLocation('documents/test.png'));
        Storage::shouldReceive('disk')->with('local')->andReturn($failedDisk);
        $this->from('/profile')->post('/profile/photo', ['photo' => UploadedFile::fake()->image('new.png')])
            ->assertRedirect('/profile')->assertSessionHasErrors('upload');
        $this->assertSame($original->id, $client->documents()->sole()->id);
        $disk->assertExists($original->path);
    }

    public function test_oversized_request_shows_upload_guidance_without_saving(): void
    {
        config(['app.debug' => false]);
        $this->signIn($this->client()->user);
        $this->call('POST', '/profile/photo', [], [], [], ['CONTENT_LENGTH' => PHP_INT_MAX])
            ->assertStatus(413)->assertSee('This request was not saved');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_archival_preserves_history_disables_access_and_pending_requests_block_archival(): void
    {
        $client = $this->client();
        $withdrawal = Withdrawal::create(['client_id' => $client->id, 'amount' => 100, 'status' => 'Pending']);
        $admin = $this->admin();
        $this->signIn($admin)->post('/admin/clients/'.$client->id.'/archive')->assertSessionHasErrors('client');
        $withdrawal->update(['status' => 'Declined']);
        $this->post('/admin/clients/'.$client->id.'/archive')->assertSessionHasNoErrors();
        $this->assertTrue($client->fresh()->archived);
        $this->assertDatabaseHas('withdrawals', ['id' => $withdrawal->id]);
        $this->signIn($client->user)->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->signIn($admin)->post('/admin/clients/'.$client->id.'/archive')->assertSessionHasNoErrors();
        $this->assertFalse($client->fresh()->archived);
    }

    public function test_qr_settings_are_available_to_clients_and_csv_injection_is_escaped(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $client = $this->client('=SUM(A1)');
        $this->signIn($admin)->post('/admin/settings', [
            'name' => 'Example Capital',
            'payment_name' => 'Example Account Holder',
            'bank_account_number' => '001234567890',
            'ifsc_code' => 'ABCD0123456',
            'upi_id' => 'example@bank',
            'payment_note' => 'Include Client ID',
            'qr' => $this->qrImage('first-qr.png'),
        ])->assertSessionHasNoErrors();
        $firstQr = Document::where('kind', 'qr')->firstOrFail();
        $this->signIn($client->user)->get('/funds')->assertOk()
            ->assertSee('Example Account Holder')
            ->assertSee('001234567890')
            ->assertSee('ABCD0123456')
            ->assertSee('example@bank')
            ->assertSee('Include Client ID')
            ->assertSee(route('documents.show', $firstQr), false);
        $this->get('/documents/'.$firstQr->id)->assertOk();

        $this->signIn($admin)->post('/admin/settings', [
            'name' => 'Example Capital Updated',
            'payment_name' => 'Updated Example Holder',
            'bank_account_number' => '987654321012',
            'ifsc_code' => 'WXYZ0987654',
            'upi_id' => 'updated@example',
            'payment_note' => 'Updated fictional instructions.',
            'qr' => $this->qrImage('updated-qr.png'),
        ])->assertSessionHasNoErrors();
        $latestQr = Document::where('kind', 'qr')->firstOrFail();
        $this->assertNotSame($firstQr->id, $latestQr->id);
        $this->assertDatabaseMissing('documents', ['id' => $firstQr->id]);

        $this->signIn($client->user)->get('/funds')->assertOk()
            ->assertSee('Updated Example Holder')
            ->assertSee('987654321012')
            ->assertSee('WXYZ0987654')
            ->assertSee('updated@example')
            ->assertSee('Updated fictional instructions.')
            ->assertSee(route('documents.show', $latestQr), false)
            ->assertDontSee('001234567890')
            ->assertDontSee('ABCD0123456')
            ->assertDontSee('example@bank');
        $this->get('/documents/'.$latestQr->id)->assertOk();

        $this->post('/admin/settings', [
            'name' => 'Unauthorized Change',
            'payment_name' => 'Unauthorized Holder',
            'bank_account_number' => '123456789012',
            'ifsc_code' => 'EFGH0123456',
        ])->assertForbidden();
        $this->assertDatabaseHas('workspace_settings', [
            'payment_name' => 'Updated Example Holder',
            'bank_account_number' => '987654321012',
            'ifsc_code' => 'WXYZ0987654',
        ]);

        $export = $this->signIn($admin)->get('/export/clients')->assertOk();
        $this->assertStringContainsString("'=SUM(A1)", $export->streamedContent());
    }

    public function test_funds_page_hides_empty_payment_details_without_placeholders(): void
    {
        $client = $this->client();

        $this->signIn($client->user)->get('/funds')->assertOk()
            ->assertDontSee('Account holder name')
            ->assertDontSee('Bank account number')
            ->assertDontSee('IFSC code')
            ->assertDontSee('Payee not configured')
            ->assertDontSee('UPI ID');
    }

    private function qrImage(string $name): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jT9sAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_withdrawal_search_accepts_displayed_ids_and_remains_client_scoped(): void
    {
        $client = $this->client();
        $other = $this->client('Other Client');
        $withdrawal = Withdrawal::create(['client_id' => $client->id, 'amount' => 10000, 'status' => 'Pending']);
        $displayId = 'WD-'.str_pad($withdrawal->id, 6, '0', STR_PAD_LEFT);
        $this->signIn($client->user);
        foreach ([$displayId, 'WD-'.$withdrawal->id, ' '.$displayId.' '] as $search) {
            $this->get('/transactions?'.http_build_query(['tab' => 'Withdrawals', 'q' => $search]))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $withdrawal->id)
                ->assertSee('Clear filters');
        }
        $this->signIn($other->user);
        $this->get('/transactions?'.http_build_query(['tab' => 'Withdrawals', 'q' => $displayId]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->isEmpty())
            ->assertSee('No records match these filters');
    }

    public function test_positions_search_accepts_client_id_and_navigation_keeps_filters(): void
    {
        $client = $this->client();
        Position::create(array_replace($this->positionData($client), ['category' => 'Options', 'pnl' => 50000]));
        $this->signIn($this->admin());
        $filters = ['category' => 'Options', 'section' => 'Last trades', 'q' => $client->code, 'client' => $client->id, 'expiry' => 'all'];
        $this->get('/positions?'.http_build_query($filters))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1)
            ->assertSee('Clear filters')
            ->assertSee(route('positions.index', ['q' => $client->code, 'client' => $client->id, 'expiry' => 'all', 'category' => 'Futures', 'section' => 'Last trades']))
            ->assertSee(route('positions.index', ['category' => 'Options', 'section' => 'Last trades']));
        $this->get('/positions?category=Options&q=NO-MATCH')->assertOk()->assertSee('No matching positions');
        $this->get('/admin/clients?archived=1&q=NO-MATCH')->assertOk()
            ->assertSee(route('clients.index', ['archived' => 1]))->assertSee('Clear filters');
    }

    public function test_money_conversion_uses_exact_paise(): void
    {
        $this->assertSame(100001,Ledger::paise('1000.01'));
        $this->assertSame(-10,Ledger::paise('-0.10'));
        $this->assertSame('₹12,34,567.89',Format::money(123456789));
    }
}
