<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * RAB (Rencana Anggaran Biaya) — the frozen quote built from AHSAP (ERD §A.2,
     * ADR-0004 layer 2 / ADR-0007). Per-project versioned; an approved RAB is
     * frozen and a revision is a NEW version. The *_percent columns snapshot the
     * margin/PPN/overhead rates used (ADR-0006) so the totals are reproducible and
     * unaffected by later changes to the global settings.
     */
    public function up(): void
    {
        Schema::create('rabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('total_material', 15, 2)->default(0);
            $table->decimal('total_upah', 15, 2)->default(0);
            $table->decimal('overhead_percent', 8, 4)->default(0);
            $table->decimal('overhead', 15, 2)->default(0);
            $table->decimal('margin_percent', 8, 4)->default(0);
            $table->decimal('margin', 15, 2)->default(0);
            $table->decimal('ppn_percent', 8, 4)->default(0);
            $table->decimal('ppn', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['project_id', 'version']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rabs');
    }
};
