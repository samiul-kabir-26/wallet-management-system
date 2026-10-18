<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $permission = 'transactions.export';

    /**
     * Run the migrations.
     *
     * Adds the catalog entry only. A ledger export is bulk personal and financial data, so ADMIN does not
     * get it by default; SUPER_ADMIN has it and can grant it to a dedicated role.
     */
    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => $this->permission],
            ['group' => 'Transactions', 'description' => 'Export the transaction ledger as CSV', 'created_at' => $now, 'updated_at' => $now],
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
