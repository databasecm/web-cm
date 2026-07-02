<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financing applications (ERD §A.4, Fase 4-1). A consumer applies to have a
     * project financed by a bank partner; the application moves through a
     * lifecycle (submitted → … → approved → disbursed, or rejected).
     *
     * bank_mitra_id references the L4 user account of the bank partner (ADR-0014
     * — no separate bank_mitra profile table yet). At most one ACTIVE (non-final)
     * financing per project is enforced in the model.
     */
    public function up(): void
    {
        Schema::create('financings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('konsumen_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('bank_mitra_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('submitted');
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('bank_mitra_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financings');
    }
};
