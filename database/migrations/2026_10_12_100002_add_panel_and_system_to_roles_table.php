<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration adds panel, is_system, and created_by to roles.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'panel')) {
                $table->string('panel')->nullable()->after('name');
            }
            if (! Schema::hasColumn('roles', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('description');
            }
            if (! Schema::hasColumn('roles', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('is_system')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('roles')->whereIn('name', ['SUPER_ADMIN', 'ADMIN'])->update([
            'panel' => 'admin',
            'is_system' => true,
        ]);

        DB::table('roles')->where('name', 'AGENT')->update([
            'panel' => 'agent',
            'is_system' => true,
        ]);

        DB::table('roles')->where('name', 'USER')->update([
            'panel' => 'user',
            'is_system' => true,
        ]);

        DB::table('roles')->whereNull('panel')->update([
            'panel' => 'user',
        ]);

        Schema::table('roles', function (Blueprint $table) {
            $table->string('panel')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
            if (Schema::hasColumn('roles', 'is_system')) {
                $table->dropColumn('is_system');
            }
            if (Schema::hasColumn('roles', 'panel')) {
                $table->dropColumn('panel');
            }
        });
    }
};
