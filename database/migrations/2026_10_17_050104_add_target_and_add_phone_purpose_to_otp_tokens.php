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
     * ADD_PHONE: a code texted to a phone number an account wants to add; `target` holds that number,
     * so the code only ever proves control of the number it was sent to.
     */
    public function up(): void
    {
        Schema::table('otp_tokens', function (Blueprint $table) {
            $table->string('target', 32)->nullable()->after('purpose');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION', 'ADD_PHONE') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('otp_tokens')->where('purpose', 'ADD_PHONE')->delete();
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION') NOT NULL");
        }

        Schema::table('otp_tokens', function (Blueprint $table) {
            $table->dropColumn('target');
        });
    }
};
