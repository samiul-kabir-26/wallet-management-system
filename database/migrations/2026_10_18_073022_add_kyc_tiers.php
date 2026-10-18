<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The column default is STANDARD so every existing account keeps its 10,000 / 50,000 limits;
     * registration sets new accounts to config('kyc.initial_tier') explicitly. caps.is_custom marks
     * limits staff set by hand, which a later tier change replaces.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('kyc_tier', 20)->default('STANDARD')->after('is_verified');
            $table->timestamp('kyc_verified_at')->nullable()->after('kyc_tier');
        });

        Schema::table('caps', function (Blueprint $table): void {
            $table->boolean('is_custom')->default(false)->after('monthly_cap');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caps', function (Blueprint $table): void {
            $table->dropColumn('is_custom');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['kyc_tier', 'kyc_verified_at']);
        });
    }
};
