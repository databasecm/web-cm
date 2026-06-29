<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Projects (ERD §A.2) — the sales/delivery hub. Created in draft straight
     * after a deal; design/RAB hang off it. `contract_value` is set from the
     * approved RAB grand_total at finalisation; `payment_scheme` is chosen at
     * checkout (nullable until then). `bank_mitra_id` references the Mitra (L4)
     * user account and is dormant until financing (Fase 3/4) — see ADR-0008;
     * BankMitraScope keys on it for §6.5.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('konsumen_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('bidang');
            $table->string('title');
            $table->string('status')->default('draft');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('payment_scheme')->nullable();
            $table->boolean('is_financed')->default(false);
            $table->foreignId('bank_mitra_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bidang', 'status']);
            $table->index('konsumen_id');
            $table->index('bank_mitra_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
