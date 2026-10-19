<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Deposit;
use App\Models\Position;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WorkspaceSetting;
use App\Services\Ledger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class CreateWealthDeskTestData extends Command
{
    protected $signature = 'wealthdesk:test-data';

    protected $description = 'Create clearly marked fictional MARWADI FINANCE QA data in a local environment';

    private const ADMIN_EMAIL = 'wealthdesk.qa.admin@example.com';

    public function handle(Ledger $ledger): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('Test data can only be created when APP_ENV=local.');

            return self::FAILURE;
        }

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        $qaAdminEmail = User::where('email', self::ADMIN_EMAIL)->first();
        if (! $admin && $qaAdminEmail) {
            $this->error('The reserved QA administrator email is already used by a non-admin account; no data was changed.');

            return self::FAILURE;
        }

        $profiles = $this->profiles();
        try {
            $existingClients = $this->existingClients($profiles);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $newClientCount = count(array_filter($existingClients, fn ($client) => $client === null));
        $adminPassword = $admin ? null : $this->promptPassword('Test administrator password (hidden input)');
        if (! $admin && ! $adminPassword) {
            return self::FAILURE;
        }
        $clientPassword = $newClientCount ? $this->promptPassword('Shared test-client password (hidden input)') : null;
        if ($newClientCount && ! $clientPassword) {
            return self::FAILURE;
        }

        $created = ['admins' => 0, 'clients' => 0, 'positions' => 0, 'deposits' => 0, 'withdrawals' => 0, 'audit_events' => 0, 'settings' => 0];

        DB::transaction(function () use ($admin, $adminPassword, $clientPassword, $profiles, $existingClients, $ledger, &$created): void {
            $actor = $admin;
            if (! $actor) {
                $actor = User::create([
                    'name' => 'MARWADI FINANCE QA Administrator',
                    'email' => self::ADMIN_EMAIL,
                    'password' => $adminPassword,
                    'role' => 'admin',
                ]);
                $created['admins']++;
            }

            $settings = WorkspaceSetting::current();
            if ($settings->wasRecentlyCreated) {
                $settings->update([
                    'name' => 'MARWADI FINANCE QA Workspace',
                    'payment_name' => 'Fictional QA Recipient',
                    'upi_id' => 'qa-marwadi-finance@example.com',
                    'payment_note' => 'Fictional test payment details. Do not transfer funds.',
                ]);
                $created['settings']++;
                $this->auditOnce($actor, null, 'settings', '[MARWADI-FINANCE-QA] Created test workspace settings', $created);
            }

            foreach ($profiles as $index => $profile) {
                $client = $existingClients[$index] ?? null;
                $isNewClient = $client === null;
                if ($isNewClient) {
                    $user = User::create([
                        'name' => $profile['name'],
                        'email' => $profile['email'],
                        'password' => $clientPassword,
                        'role' => 'client',
                    ]);
                    $client = Client::create([
                        'user_id' => $user->id,
                        'code' => $profile['code'],
                        'phone' => $profile['phone'],
                        'pan' => null,
                        'risk' => $profile['risk'],
                        'investment' => $profile['investment'],
                        'details' => $profile['details'],
                        'sample' => true,
                    ]);
                    $created['clients']++;
                    $this->auditOnce($actor, $client, 'client', sprintf('[MARWADI-FINANCE-QA] Created fictional client %02d', $index + 1), $created);

                    $ledger->change($client->id, function (Client $locked) use ($index, $actor, &$created): void {
                        $this->createFinancialRecords($locked, $index, $actor, $created);
                    });
                }

                if ($isNewClient && $profile['archived']) {
                    $ledger->change($client->id, function (Client $locked) use ($index, $actor, &$created): void {
                        if ($locked->withdrawals()->where('status', 'Pending')->exists()) {
                            throw new RuntimeException('A QA client with a pending withdrawal cannot be archived.');
                        }
                        $locked->update(['archived' => true]);
                        $this->auditOnce($actor, $locked, 'client', sprintf('[MARWADI-FINANCE-QA] Archived fictional client %02d', $index + 1), $created);
                    }, true);
                }
            }
        }, 3);

        $this->info(sprintf(
            'QA data ready. Created %d admin, %d clients, %d positions, %d deposits, %d withdrawals, %d audit events, and %d settings rows. Existing QA records were left unchanged; no documents were generated.',
            $created['admins'], $created['clients'], $created['positions'], $created['deposits'], $created['withdrawals'], $created['audit_events'], $created['settings']
        ));

        return self::SUCCESS;
    }

    private function promptPassword(string $prompt): ?string
    {
        $password = $this->secret($prompt);
        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', 'max:128', Password::min(10)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            $this->error('Password must be 10-128 characters and include at least one letter and one number. No data was changed.');

            return null;
        }

        return $password;
    }

    private function profiles(): array
    {
        $people = [
            ['Avery', 'Morgan', 'Low'], ['Riley', 'Chen', 'Medium'], ['Jordan', 'Patel', 'High'], ['Casey', 'Brooks', 'Advanced'],
            ['Taylor', 'Kim', 'Low'], ['Morgan', 'Ellis', 'Medium'], ['Cameron', 'Reed', 'High'], ['Skyler', 'Bennett', 'Advanced'],
            ['Quinn', 'Carter', 'Low'], ['Dakota', 'Lane', 'Medium'], ['Rowan', 'Parker', 'High'], ['Jamie', 'Stone', 'Advanced'],
            ['Alex', 'Murphy', 'Low'], ['Emery', 'Hayes', 'Medium'], ['Finley', 'James', 'High'], ['Sage', 'Turner', 'Advanced'],
            ['Devon', 'Price', 'Low'], ['Blair', 'Cooper', 'Medium'], ['Reese', 'Bell', 'High'], ['Ellis', 'Grant', 'Advanced'],
        ];
        $nominees = ['Parent', 'Sibling', 'Spouse', 'Other', 'None'];

        return collect($people)->values()->map(function (array $person, int $index) use ($nominees): array {
            [$firstName, $surname, $risk] = $person;
            $number = $index + 1;
            $nominee = $nominees[$index % count($nominees)];

            return [
                'code' => sprintf('MFSLQA%02d', $number),
                'name' => $firstName.' '.$surname,
                'email' => sprintf('wealthdesk.qa.client%02d@example.com', $number),
                'phone' => sprintf('+1 202 555 %04d', 100 + $index),
                'risk' => $risk,
                'investment' => (20_000 + ($index * 250)) * 100,
                'archived' => $number > 18,
                'details' => [
                    'first_name' => $firstName,
                    'surname' => $surname,
                    'dob' => Carbon::now()->subYears(25 + $index)->format('Y-m-d'),
                    'address' => sprintf('Fictional QA address %02d, Example City', $number),
                    'registration' => sprintf('QA-REG-%04d', $number),
                    'provide' => 'Fictional profile for local QA.',
                    'account_type' => $index % 2 ? 'Current' : 'Savings',
                    'nominee' => $nominee,
                    'nominee_name' => $nominee === 'None' ? null : sprintf('Fictional Nominee %02d', $number),
                ],
            ];
        })->all();
    }

    private function existingClients(array $profiles): array
    {
        $existing = [];
        foreach ($profiles as $index => $profile) {
            $user = User::with('client')->where('email', $profile['email'])->first();
            if (! $user) {
                if (Client::where('code', $profile['code'])->exists() || $this->hasOrphanedMarkers($index)) {
                    throw new RuntimeException('A reserved QA client ID or record marker is already in use; no data was changed.');
                }
                $existing[$index] = null;

                continue;
            }

            $client = $user->client;
            if ($user->role !== 'client' || ! $client || ! $client->sample) {
                throw new RuntimeException('A reserved QA email or client ID is already used by a non-QA account; no data was changed.');
            }
            $this->assertExistingQaRecords($client, $index);
            $existing[$index] = $client;
        }

        return $existing;
    }

    private function hasOrphanedMarkers(int $index): bool
    {
        $number = $index + 1;
        if (Deposit::withTrashed()->where('reference', $this->depositReference($number, 'COMPLETED'))->exists()
            || Deposit::withTrashed()->where('reference', $this->depositReference($number, 'PENDING'))->exists()
            || Withdrawal::where('note', $this->withdrawalNote($number))->exists()) {
            return true;
        }

        foreach ($this->positionSpecs($index) as $spec) {
            if (Position::withTrashed()->where('stock', $spec['stock'])->exists()) {
                return true;
            }
        }

        return false;
    }

    private function assertExistingQaRecords(Client $client, int $index): void
    {
        $number = $index + 1;
        foreach (['COMPLETED', 'PENDING'] as $status) {
            $deposit = Deposit::withTrashed()->where('reference', $this->depositReference($number, $status))->first();
            if (! $deposit || $deposit->client_id !== $client->id) {
                throw new RuntimeException('An existing QA client has an incomplete test-data batch; no records were changed.');
            }
        }

        foreach ($this->positionSpecs($index) as $spec) {
            $position = Position::withTrashed()->where('stock', $spec['stock'])->first();
            if (! $position || $position->client_id !== $client->id || $position->section !== $spec['section'] || $position->category !== $spec['category']) {
                throw new RuntimeException('An existing QA client has an incomplete test-data batch; no records were changed.');
            }
        }

        if (! Withdrawal::where('client_id', $client->id)->where('note', $this->withdrawalNote($number))->exists()) {
            throw new RuntimeException('An existing QA client has an incomplete test-data batch; no records were changed.');
        }
    }

    private function createFinancialRecords(Client $client, int $index, User $actor, array &$created): void
    {
        $number = $index + 1;
        $date = Carbon::now()->subDays($number);
        foreach ([['COMPLETED', 'Completed', 100_000 + ($index * 1_000)], ['PENDING', 'Pending', 10_000 + ($index * 500)]] as [$marker, $status, $amount]) {
            Deposit::create([
                'client_id' => $client->id,
                'date' => $date,
                'amount' => $amount,
                'processed_by' => 'MARWADI FINANCE QA',
                'reference' => $this->depositReference($number, $marker),
                'status' => $status,
            ]);
            $created['deposits']++;
            $this->auditOnce($actor, $client, 'deposit', sprintf('[MARWADI-FINANCE-QA] Recorded %s deposit %02d', strtolower($status), $number), $created);
        }

        foreach ($this->positionSpecs($index) as $spec) {
            Position::create(['client_id' => $client->id] + $spec + ['date' => $date]);
            $created['positions']++;
            $this->auditOnce($actor, $client, 'position', '[MARWADI-FINANCE-QA] Recorded '.$spec['section'].' for client '.sprintf('%02d', $number), $created);
        }

        $targetStatus = $this->withdrawalStatus($index);
        $withdrawal = Withdrawal::create([
            'client_id' => $client->id,
            'amount' => 25_000,
            'status' => 'Pending',
            'note' => $this->withdrawalNote($number),
        ]);
        $created['withdrawals']++;
        $this->auditOnce($actor, $client, 'withdrawal', '[MARWADI-FINANCE-QA] Requested withdrawal '.sprintf('%02d', $number), $created);
        if ($targetStatus !== 'Pending') {
            $withdrawal->update(['status' => $targetStatus, 'decided_at' => now(), 'decided_by' => $actor->id]);
            $this->auditOnce($actor, $client, 'withdrawal', sprintf('[MARWADI-FINANCE-QA] %s withdrawal %02d', $targetStatus, $number), $created);
        }
    }

    private function positionSpecs(int $index): array
    {
        $number = $index + 1;
        $categories = Ledger::CATEGORIES;
        $currentCategory = $categories[$index % count($categories)];
        $tradeCategory = $categories[($index + 1) % count($categories)];
        $negativeTrade = $index % 2 === 1;
        $specs = [
            [
                'category' => $currentCategory,
                'section' => 'Current positions',
                'stock' => sprintf('QA%02d-CURRENT', $number),
                'side' => $index % 2 ? 'Sell' : 'Buy',
                'quantity' => 10,
                'entry_price' => 100_000,
                'ltp' => $index % 2 ? 100_500 : 100_500,
                'exit_price' => null,
                'pnl' => $index % 2 ? -5_000 : 5_000,
                'state' => $index % 2 ? 'Hold' : 'Active',
            ],
            [
                'category' => $tradeCategory,
                'section' => 'Last trades',
                'stock' => sprintf('QA%02d-LAST', $number),
                'side' => 'Buy',
                'quantity' => 5,
                'entry_price' => 50_000,
                'ltp' => $negativeTrade ? 49_000 : 51_000,
                'exit_price' => $negativeTrade ? 49_000 : 51_000,
                'pnl' => $negativeTrade ? -5_000 : 5_000,
                'state' => 'Closed',
            ],
        ];

        if ($currentCategory !== 'Forex & Currency') {
            $specs[] = [
                'category' => $currentCategory,
                'section' => 'Orders',
                'stock' => sprintf('QA%02d-ORDER', $number),
                'side' => 'Buy',
                'quantity' => 2,
                'entry_price' => 25_000,
                'ltp' => 25_000,
                'exit_price' => null,
                'pnl' => 0,
                'state' => 'Active',
            ];
            $specs[] = [
                'category' => $currentCategory,
                'section' => 'Total value of trade',
                'stock' => sprintf('QA%02d-TOTAL', $number),
                'side' => 'Sell',
                'quantity' => 2,
                'entry_price' => 25_000,
                'ltp' => 25_000,
                'exit_price' => null,
                'pnl' => 0,
                'state' => 'Active',
            ];
        }

        return $specs;
    }

    private function withdrawalStatus(int $index): string
    {
        if ($index === 18) {
            return 'Approved';
        }
        if ($index === 19) {
            return 'Declined';
        }

        return ['Pending', 'Approved', 'Declined'][$index % 3];
    }

    private function depositReference(int $number, string $status): string
    {
        return sprintf('WDQA-%02d-%s', $number, $status);
    }

    private function withdrawalNote(int $number): string
    {
        return sprintf('[MARWADI-FINANCE-QA:WITHDRAWAL:%02d] Fictional test request.', $number);
    }

    private function auditOnce(User $actor, ?Client $client, string $action, string $description, array &$created): void
    {
        $event = AuditEvent::firstOrCreate([
            'actor_id' => $actor->id,
            'client_id' => $client?->id,
            'action' => $action,
            'description' => $description,
        ]);
        if ($event->wasRecentlyCreated) {
            $created['audit_events']++;
        }
    }
}