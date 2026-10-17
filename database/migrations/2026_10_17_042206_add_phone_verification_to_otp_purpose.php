<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PHONE_VERIFICATION: a code sent by SMS that proves the account holder controls their phone number.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('otp_tokens')->where('purpose', 'PHONE_VERIFICATION')->delete();
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN') NOT NULL");
        }
    }
};
