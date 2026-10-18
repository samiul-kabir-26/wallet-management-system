<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * REGISTRATION: a code texted to a number before its account exists, so user_id is null and
     * `target` holds the phone number the code proves control of.
     */
    public function up(): void
    {
        Schema::table('otp_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->index(['purpose', 'target']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION', 'ADD_PHONE', 'REGISTRATION') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('otp_tokens')->whereNull('user_id')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::table('otp_tokens')->where('purpose', 'REGISTRATION')->delete();
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION', 'ADD_PHONE') NOT NULL");
        }

        Schema::table('otp_tokens', function (Blueprint $table) {
            $table->dropIndex(['purpose', 'target']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
