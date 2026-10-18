<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $permission = 'transactions.reverse';

    /**
     * Run the migrations.
     *
     * Adds the catalog entry only. Like agents.issue_float it moves money, so ADMIN does not get it by
     * default; SUPER_ADMIN holds it through Gate::before and can grant it to a dedicated role.
     */
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => $this->permission],
            ['group' => 'Transactions', 'description' => 'Reverse a completed transfer, cash-in or cash-out', 'created_at' => $now, 'updated_at' => $now],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', $this->permission)->value('id');

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
