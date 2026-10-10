<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope idempotency keys to the user who initiated the transaction.
     *
     * A globally unique key let one user's key collide with another's, and the
     * service's duplicate-key fallback would then return the other user's
     * transaction. Uniqueness per initiator keeps keys client-generated while
     * guaranteeing a key can only ever resolve to the caller's own record.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unique(['initiated_by', 'idempotency_key']);
            $table->dropUnique(['idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unique('idempotency_key');
            $table->dropUnique(['initiated_by', 'idempotency_key']);
        });
    }
};
