<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('code', 32)->change();
        });

        $highestNumber = 0;
        foreach (DB::table('clients')->pluck('code') as $code) {
            if (preg_match('/^MFSL(\d+)$/', $code, $matches)) {
                $highestNumber = max($highestNumber, (int) $matches[1]);
            }
        }

        Schema::create('client_id_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_number');
        });

        DB::table('client_id_sequences')->insert([
            'id' => 1,
            'next_number' => $highestNumber + 1,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('client_id_sequences');
    }
};
