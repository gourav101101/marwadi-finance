<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Ledger
{
    public const CATEGORIES = ['Equity', 'Futures', 'Options', 'Commodity', 'Forex & Currency'];

    public const LEGACY_CATEGORY = 'Futures & Options';

    public const EXPIRY_CATEGORIES = ['Futures', 'Options', self::LEGACY_CATEGORY];

    public const EXPIRY_WARNING_DAYS = 7;

    public const SECTIONS = ['Current positions', 'Last trades', 'Orders', 'Total value of trade'];

    public const RISKS = ['Low', 'Medium', 'High', 'Advanced'];

    public static function paise(string|int|float $value): int
    {
        $value = (string) $value;
        if (! preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid amount with at most two decimal places.']);
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));

        return ((int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0')) * ($negative ? -1 : 1);
    }

    public function balance(Client $client): array
    {
        $deposits = (int) $client->deposits()->where('status', 'Completed')->sum('amount');
        $realized = (int) $client->positions()
            ->where(fn ($query) => $query->where('section', 'Last trades')
                ->orWhere(fn ($query) => $query->where('section', 'Current positions')->where('state', 'Closed')))
            ->sum('pnl');
        $approved = (int) $client->withdrawals()->where('status', 'Approved')->sum('amount');
        $pending = (int) $client->withdrawals()->where('status', 'Pending')->sum('amount');
        $balance = $client->investment + $deposits + $realized - $approved;

        return compact('balance', 'deposits', 'realized', 'approved', 'pending') + ['available' => $balance - $pending];
    }

    public function change(int $clientId, callable $callback, bool $allowArchived = false): mixed
    {
        return DB::transaction(function () use ($clientId, $callback, $allowArchived) {
            // Acquire a write lock first, including on SQLite where FOR UPDATE is ignored.
            Client::whereKey($clientId)->increment('lock_version');
            $client = Client::lockForUpdate()->findOrFail($clientId);
            if ($client->archived && ! $allowArchived) {
                throw ValidationException::withMessages(['client' => 'This client is archived. Restore the account first.']);
            }
            $result = $callback($client);
            if ($this->balance($client->refresh())['available'] < 0) {
                throw ValidationException::withMessages(['amount' => 'This change exceeds the available balance, including reserved withdrawals.']);
            }

            return $result;
        }, 5);
    }

    public static function audit(string $action, string $description, ?int $clientId = null): void
    {
        AuditEvent::create(['actor_id' => auth()->id(), 'client_id' => $clientId, 'action' => $action, 'description' => $description]);
    }
}
