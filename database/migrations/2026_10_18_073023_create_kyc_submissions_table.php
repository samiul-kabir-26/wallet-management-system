<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Identity documents a customer sends for verification. Images live on the private disk and the
     * document number is encrypted by the model, so neither is readable from a database dump alone.
     */
    public function up(): void
    {
        Schema::create('kyc_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 20);
            $table->text('document_number');
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('front_path');
            $table->string('back_path');
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_submissions');
    }
};
