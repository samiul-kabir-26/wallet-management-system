<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A REVERSAL row points at the transaction it undoes. The unique index is what guarantees a
     * transaction can be reversed only once, even when two staff members submit at the same moment.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE', 'SIGNUP_BONUS', 'REVERSAL') NOT NULL");
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('reversed_transaction_id')
                ->nullable()
                ->after('external_reference')
                ->unique()
                ->constrained('transactions')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Refuses to roll back while reversal rows exist, because narrowing the enum would corrupt them.
     */
    public function down(): void
    {
        if (DB::table('transactions')->where('type', 'REVERSAL')->exists()) {
            throw new RuntimeException('Cannot remove REVERSAL from transactions.type while reversal transactions exist.');
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_transaction_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT', 'FLOAT_ISSUE', 'SIGNUP_BONUS') NOT NULL");
        }
    }
};
