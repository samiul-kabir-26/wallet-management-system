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
        Schema::create('otp_tokens', function (Blueprint $table) {
            $table->id();
         
            $table->unsignedBigInteger('user_id');
            $table->string('otp_code');
            $table->enum('purpose',['LOGIN', 'PASSWORD_RESET']);
            $table->timestamp('used_at')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('max_attempts')->default(5);
            $table->timestamp('expires_at');
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
        Schema::dropIfExists('otp_tokens');
    }
};
