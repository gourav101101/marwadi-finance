<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->string('bank_account_number', 18)->nullable()->after('payment_name');
            $table->string('ifsc_code', 11)->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn(['bank_account_number', 'ifsc_code']);
        });
    }
};