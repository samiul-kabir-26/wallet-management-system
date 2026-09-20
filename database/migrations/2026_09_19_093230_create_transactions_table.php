<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->unsignedBigInteger('initiated_by');
            $table->enum('type',['TOP_UP', 'CASH_IN', 'CASH_OUT', 'TRANSFER', 'AGENT_WITHDRAWAL', 'COMMISSION_PAYOUT']);
            $table->decimal('amount', 19, 2);
            $table->decimal('system_fee_amount', 19, 2);
            $table->decimal('system_fee_rate', 6, 4)->default(0.0000);
            $table->decimal('agent_commission_amount', 19, 2)->default(0.00);
            $table->decimal('agent_commission_rate', 6, 4)->default(0.0000);
            $table->string('currency')->default('BDT');
            $table->text('description')->nullable();
            $table->enum('status',['COMPLETED', 'FAILED'])->default('COMPLETED');
            $table->string('idempotency_key')->nullable()->unique();
            $table->decimal('sender_wallet_balance_after', 19, 2)->nullable();
            $table->decimal('recipient_wallet_balance_after', 19, 2)->nullable();
            $table->decimal('agent_wallet_balance_after', 19, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // FK constraints last
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('recipient_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('initiated_by')->references('id')->on('users')->onDelete('cascade');

        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
