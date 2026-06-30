<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Line items of a RAB (ERD §A.2). Each item snapshots its description, unit
     * and unit_price from the source AHSAP at build time (ADR-0007); `ahsap_id`
     * is kept ONLY as a provenance trail — the numbers never read it live, so an
     * AHSAP resync can never move an existing RAB.
     */
    public function up(): void
    {
        Schema::create('rab_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rab_id')->constrained('rabs')->cascadeOnDelete();
            $table->foreignId('ahsap_id')->nullable()->constrained('ahsap')->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('volume', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();

            $table->index('rab_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rab_items');
    }
};
