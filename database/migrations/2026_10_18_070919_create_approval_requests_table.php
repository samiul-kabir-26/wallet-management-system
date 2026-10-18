<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Maker-checker: a sensitive staff action is stored here by one staff member (the maker) and only
     * applied when a different staff member (the checker) approves it.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 40)->index();
            $table->string('status', 20)->default('PENDING')->index();
            $table->json('payload');
            $table->string('summary', 255);
            $table->nullableMorphs('subject');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['action', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
