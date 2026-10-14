<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE') NOT NULL");
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('external_reference', 255)->nullable()->unique()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['external_reference']);
            $table->dropColumn('external_reference');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT') NOT NULL");
        }
    }
};
