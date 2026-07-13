<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workers / employees (ERD §A.5, Fase 5-1).
     *
     * HARD RULE (CLAUDE.md §7): an employee is a DATA ENTITY, not a login account.
     * There is deliberately NO identity FK to `users` and no credential columns —
     * an employee can never authenticate. It is managed as data (attendance by
     * the Mandor, payroll by HR). `managed_by` is the managing Mandor's user
     * account, an attribution — not the employee's identity.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bidang');
            $table->string('type')->default('harian'); // harian|bulanan
            $table->decimal('daily_wage', 15, 2)->default(0);
            $table->string('position')->nullable();
            $table->string('status')->default('aktif'); // aktif|nonaktif
            $table->foreignId('managed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bidang', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
