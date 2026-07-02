<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only trail of every financing status transition (ERD §A.4, Fase
     * 4-1) — one row per transition, like material_price_history. Written by
     * Financing::transitionTo().
     */
    public function up(): void
    {
        Schema::create('financing_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financing_id')->constrained('financings')->cascadeOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('financing_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financing_status_logs');
    }
};
