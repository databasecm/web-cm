<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Payment installments generated at checkout from the chosen scheme (konsep
     * §5, ERD §A.4). `due_condition` controls when a term unlocks; the checkout
     * term starts unlocked, progress50/bast start locked. No real payment / VA
     * yet (Fase 3) — va_number/paid_at stay null.
     */
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('term_no');
            $table->string('label');
            $table->decimal('percentage', 8, 4);
            $table->decimal('amount', 15, 2);
            $table->string('due_condition');
            $table->string('status')->default('locked');
            $table->string('va_number')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'term_no']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
