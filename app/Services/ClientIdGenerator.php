<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use LogicException;

class ClientIdGenerator
{
    public function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Client IDs must be generated within the client creation transaction.');
        }

        do {
            DB::table('client_id_sequences')->where('id', 1)->increment('next_number');
            $number = (int) DB::table('client_id_sequences')->where('id', 1)->value('next_number') - 1;
            $code = 'MFSL'.$number;
        } while (Client::where('code', $code)->exists());

        return $code;
    }
}
