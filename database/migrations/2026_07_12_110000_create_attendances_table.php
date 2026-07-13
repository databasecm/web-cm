<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily worker attendance (ERD §A.5, Fase 5-2) — the SOURCE OF TRUTH for
     * daily payroll (Fase 6). Recorded by the Mandor, one row per worker per day.
     *
     * ANTI-DOUBLE (confirmed with the owner): a worker attends ONE project per
     * day, so the unique key is (employee_id, date) — NOT
     * (employee_id, project_id, date). This is the guard against double wages in
     * payroll and is enforced at the DB level as well as in the service.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->date('date');
            $table->string('status'); // hadir|izin|alpa
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            // One attendance per worker per day (one project/day). Payroll guard.
            $table->unique(['employee_id', 'date']);
            $table->index(['project_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
