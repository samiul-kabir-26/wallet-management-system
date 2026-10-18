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
     * - personal_access_tokens: where each session came from, for the session list.
     * - trusted_devices: devices a customer or agent proved with an SMS code (device binding).
     * - users: staff authenticator-app (TOTP) secret, encrypted by the model.
     * - otp_tokens.purpose: DEVICE_VERIFICATION.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });

        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('label', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->text('totp_secret')->nullable()->after('password_changed_at');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->unsignedBigInteger('totp_last_used_step')->nullable()->after('totp_confirmed_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION', 'ADD_PHONE', 'REGISTRATION', 'DEVICE_VERIFICATION') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('otp_tokens')->where('purpose', 'DEVICE_VERIFICATION')->delete();
            DB::statement("ALTER TABLE otp_tokens MODIFY COLUMN purpose ENUM('LOGIN', 'PASSWORD_RESET', 'SET_PIN', 'PHONE_VERIFICATION', 'ADD_PHONE', 'REGISTRATION') NOT NULL");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_used_step']);
        });

        Schema::dropIfExists('trusted_devices');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
