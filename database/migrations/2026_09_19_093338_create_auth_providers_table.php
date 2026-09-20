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
        Schema::create('auth_providers', function (Blueprint $table) {
            $table->id();
          
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            $table->string('provider_id')->unique();
            $table->timestamps();

            // FK constraint last
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::dropIfExists('auth_providers');
    }
};
