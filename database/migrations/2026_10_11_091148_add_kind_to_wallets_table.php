<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add nullable kind column to wallets
        Schema::table('wallets', function (Blueprint $table) {
            $table->enum('kind', ['USER', 'AGENT'])->nullable()->after('user_id');
        });

        // 2. Backfill existing rows with query builder (not Eloquent models)
        // Clean up unused initial wallets for pure staff accounts (ADMIN, MODERATOR) without transacting roles
        $staffOnlyUserIds = DB::table('user_roles')
            ->join('roles', 'user_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['ADMIN', 'MODERATOR'])
            ->whereNotIn('user_roles.user_id', function ($query) {
                $query->select('user_roles.user_id')
                    ->from('user_roles')
                    ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                    ->whereIn('roles.name', ['USER', 'AGENT']);
            })
            ->pluck('user_roles.user_id');

        if ($staffOnlyUserIds->isNotEmpty()) {
            DB::table('wallets')
                ->whereIn('user_id', $staffOnlyUserIds)
                ->where('balance', '50.00')
                ->delete();
        }

        // Default all existing wallets to USER
        DB::table('wallets')->whereNull('kind')->update(['kind' => 'USER']);

        // A wallet belongs to an AGENT if its user holds the AGENT role (join user_roles and roles)
        DB::table('wallets')
            ->whereIn('user_id', function ($query) {
                $query->select('user_roles.user_id')
                    ->from('user_roles')
                    ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                    ->where('roles.name', 'AGENT');
            })
            ->update(['kind' => 'AGENT']);

        // 3. Make kind non-nullable after the backfill
        Schema::table('wallets', function (Blueprint $table) {
            $table->enum('kind', ['USER', 'AGENT'])->nullable(false)->change();
        });

        // 4. Add a composite unique index on (user_id, kind)
        Schema::table('wallets', function (Blueprint $table) {
            $table->unique(['user_id', 'kind']);
        });

        // 5. Only then drop the old unique index on user_id (MySQL uses the composite index for the FK)
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique('wallets_user_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Refuse to rollback if any user has multiple wallets, preventing financial record destruction
        $duplicateCount = DB::table('wallets')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateCount > 0) {
            throw new RuntimeException("Cannot rollback migration: {$duplicateCount} user(s) have multiple wallets with active balances. Consolidate or resolve multi-wallet accounts before rolling back.");
        }

        // 5 reversed: Re-add unique index on user_id before dropping composite index
        Schema::table('wallets', function (Blueprint $table) {
            $table->unique('user_id', 'wallets_user_id_unique');
        });

        // 4 reversed: Drop composite unique index
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'kind']);
        });

        // 3, 2, 1 reversed: Drop kind column
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
