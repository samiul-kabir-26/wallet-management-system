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
        Schema::create('caps', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('user_id')->unique();
            $table->decimal('daily_cap', 19, 2)->default(10000.00);
            $table->decimal('monthly_cap', 19, 2)->default(50000.00);
            $table->decimal('daily_used', 19, 2)->default(0.00);
            $table->decimal('monthly_used', 19, 2)->default(0.00);
            $table->timestamps();

            // FK constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caps');
    }
};
