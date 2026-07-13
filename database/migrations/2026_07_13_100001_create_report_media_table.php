<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos/videos attached to a daily report (ERD §A.5, Fase 5-3). `file` is a
     * path/link placeholder — binary upload to object storage is deferred to the
     * media task (ADR-0015).
     */
    public function up(): void
    {
        Schema::create('report_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('daily_reports')->cascadeOnDelete();
            $table->string('type'); // photo|video
            $table->string('file')->nullable();
            $table->string('caption')->nullable();
            $table->timestamps();

            $table->index('daily_report_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_media');
    }
};
