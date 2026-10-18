<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An agent's claim that they deposited money at the bank and want the same amount as e-money float.
     * Staff check it against the bank statement; approval issues a FLOAT_ISSUE transaction whose unique
     * external_reference is the bank reference, so one deposit can never be credited twice.
     */
    public function up(): void
    {
        Schema::create('float_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 19, 2);
            $table->string('bank_reference', 100)->index();
            $table->date('deposited_at');
            $table->string('slip_path');
            $table->string('slip_original_name');
            $table->string('slip_mime_type', 100);
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('float_requests');
    }
};
