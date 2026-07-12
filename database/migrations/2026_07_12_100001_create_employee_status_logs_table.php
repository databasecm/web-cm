<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only history of an employee's position/wage changes (ERD §A.5, Fase
     * 5-1). Written by EmployeeService when a promotion or salary change is
     * recorded — a paper trail for HR.
     */
    public function up(): void
    {
        Schema::create('employee_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('change_type'); // promotion|salary
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->date('effective_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_status_logs');
    }
};
