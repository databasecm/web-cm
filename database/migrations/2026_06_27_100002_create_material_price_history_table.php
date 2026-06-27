<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Canonical trail of material prices (ERD §A.3, ADR-0004). One row per price
     * point: an initial row on create, then one per change. Never overwritten —
     * this is how field price movements stay traceable and how AHSAP staleness is
     * justified (Fase 2A-3).
     */
    public function up(): void
    {
        Schema::create('material_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['material_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_price_history');
    }
};
