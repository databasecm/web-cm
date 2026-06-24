<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds RBAC attributes to user accounts:
     * - role_id      primary role (source of the hierarchy level)
     * - bidang       business unit scope for Manager & Mandor
     * - is_protected protects the Owner account from deletion
     * - created_by   tracks which account created this one (hierarchy)
     * - deleted_at   soft deletes
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained('roles')->nullOnDelete();
            $table->enum('bidang', ['cufid', 'cc', 'solit', 'birugis'])->nullable()->after('role_id');
            $table->boolean('is_protected')->default(false)->after('bidang');
            $table->foreignId('created_by')->nullable()->after('is_protected')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['created_by']);
            $table->dropColumn(['role_id', 'bidang', 'is_protected', 'created_by', 'deleted_at']);
        });
    }
};
