<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily field reports by the Mandor (ERD §A.5, Fase 5-3). One report per
     * project per day. `progress_note` is a NARRATIVE note only — it never
     * advances project.progress_percent (that stays a Manager action via
     * ProgressService, 2B-6), so a daily report never unlocks a payment term.
     */
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('mandor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->text('description');
            $table->text('progress_note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'date']); // one report per project per day
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
