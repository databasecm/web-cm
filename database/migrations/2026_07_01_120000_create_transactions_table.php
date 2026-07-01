<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Finance cash book (ERD §A.6). Every money movement is one row: consumer
     * installment payments flow in here as income (category pembayaran_konsumen),
     * so the Finance module (Fase 6) is fed automatically from the right source.
     *
     * `reference_type` is a logical tag ('installment'|'payroll'|'po'|'manual')
     * paired with `reference_id` — not a Laravel morph to a class — matching the
     * ERD. Financial rows are Auditable (CLAUDE.md §6.6).
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type');     // income|expense
            $table->string('category'); // pembayaran_konsumen|investor|material|operasional|gaji|lainnya
            $table->decimal('amount', 15, 2);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->timestamps();

            $table->index(['type', 'category']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
