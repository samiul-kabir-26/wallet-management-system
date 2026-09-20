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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone_number')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('pin')->nullable();
            $table->enum('user_type',['ADMIN_TRACK', 'USER_TRACK'])->default('USER_TRACK');
            $table->string('image')->nullable();
            $table->text('address')->nullable();
            $table->enum('is_active', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
