<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PENDING: funds are held (already debited) while staff review an agent bank withdrawal.
     * REJECTED: staff declined the withdrawal and the held funds were returned to the wallet.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('COMPLETED', 'FAILED', 'PENDING', 'REJECTED') NOT NULL DEFAULT 'COMPLETED'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN status ENUM('COMPLETED', 'FAILED') NOT NULL DEFAULT 'COMPLETED'");
        }
    }
};
