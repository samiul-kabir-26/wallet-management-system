<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only record of every login attempt on the three panels. user_id is NULL when the
     * identifier matched no account, so probing for unknown numbers is still visible.
     */
    public function up(): void
    {
        Schema::create('login_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('panel', ['admin', 'agent', 'user']);
            $table->string('identifier');
            $table->enum('status', ['SUCCESS', 'FAILED']);
            $table->string('failure_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_activities');
    }
};
