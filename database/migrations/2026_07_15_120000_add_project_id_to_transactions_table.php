<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a cash-book row to the project it belongs to (Fase 6-3b) for
     * per-project profit & loss. Nullable: a row need not belong to a project —
     * salary (gaji) is deliberately left NULL because one weekly payroll spans
     * many projects (workers move between them day to day), so it cannot be
     * attributed to a single project without splitting per payslip per
     * attendance; it counts as unallocated overhead instead.
     *
     * nullOnDelete keeps the financial row intact if the project is later
     * removed (the money movement still happened) — the same pattern as
     * recorded_by.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('reference_id')
                ->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
