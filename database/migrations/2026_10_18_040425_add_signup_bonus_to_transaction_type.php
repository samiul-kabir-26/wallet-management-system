<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE', 'SIGNUP_BONUS') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Refuses to roll back while signup bonus rows exist, because narrowing the enum would corrupt them.
     */
    public function down(): void
    {
        if (DB::table('transactions')->where('type', 'SIGNUP_BONUS')->exists()) {
            throw new RuntimeException('Cannot remove SIGNUP_BONUS from transactions.type while signup bonus transactions exist.');
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE') NOT NULL");
        }
    }
};
