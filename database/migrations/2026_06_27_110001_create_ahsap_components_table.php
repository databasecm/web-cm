<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Components of an AHSAP (ERD §A.3): material, labour (upah) or tools (alat).
     * For a material component, `unit_price` is a SNAPSHOT of Material.price taken
     * when the component is added/synced (ADR-0004) — not a live join — so the
     * AHSAP stays self-contained and stable. For upah/alat it is entered manually.
     */
    public function up(): void
    {
        Schema::create('ahsap_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ahsap_id')->constrained('ahsap')->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('coefficient', 12, 4)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->timestamps();

            $table->index('ahsap_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ahsap_components');
    }
};
