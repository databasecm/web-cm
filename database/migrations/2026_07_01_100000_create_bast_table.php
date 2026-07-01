<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Berita Acara Serah Terima — the handover record (ERD §A.4). Exactly one
     * BAST per project (unique project_id → 1—1). It starts as `draft` and can
     * only become `signed` once both parties have signed; the signed state
     * unlocks the pelunasan installment (wired in Fase 3-2, CLAUDE.md §7).
     *
     * `file` is a path/link placeholder for now; binary upload to object storage
     * is deferred to the media phase, consistent with `designs`.
     */
    public function up(): void
    {
        Schema::create('bast', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('file')->nullable();
            $table->boolean('signed_customer')->default(false);
            $table->boolean('signed_company')->default(false);
            $table->timestamp('signed_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique('project_id'); // one BAST per project (1—1)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bast');
    }
};
