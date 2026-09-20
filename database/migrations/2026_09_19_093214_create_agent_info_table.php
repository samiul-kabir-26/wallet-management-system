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
        Schema::create('agent_info', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->unique();
            $table->enum('status',['PENDING', 'APPROVED', 'SUSPENDED', 'DELETED'])->default('PENDING');
            $table->decimal('commission_rate', 6, 4)->default(0.0001);
            $table->decimal('total_commission', 19, 2)->default(0.00);
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
            $table->timestamps();

            // FK constraints 
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('suspended_by')->references('id')->on('users')->onDelete('set null');

            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_info');
    }
};
