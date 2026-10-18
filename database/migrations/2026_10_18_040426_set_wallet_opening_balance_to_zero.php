<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Wallets used to open at a 50.00 column default, which put money in a wallet with no ledger entry behind it.
     * They now open empty and the signup bonus is credited as a SIGNUP_BONUS transaction.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('balance', 19, 2)->default(0.00)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('balance', 19, 2)->default(50.00)->change();
        });
    }
};
