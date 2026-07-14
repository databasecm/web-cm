<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll runs (ERD §A.5, Fase 6-1). A weekly_daily run covers a Mon–Sat
     * period paid on the Saturday (period_end). One run per (period, type) —
     * unique — so re-generating is idempotent, never duplicated. Auditable.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end'); // the payday Saturday
            $table->string('type')->default('weekly_daily'); // weekly_daily|monthly
            $table->string('status')->default('draft');      // draft|approved|paid
            $table->timestamps();

            $table->unique(['period_start', 'period_end', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
