<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Analisa Harga Satuan Pekerjaan (ERD §A.3) — the unit-price analysis that
     * underpins RAB. `base_price` is a CALCULATED column: the sum over its
     * components, recomputed by AhsapCalculator and never edited by hand.
     */
    public function up(): void
    {
        Schema::create('ahsap', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('bidang');
            $table->string('unit');
            $table->decimal('base_price', 15, 2)->default(0);
            $table->timestamps();

            $table->index('bidang');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ahsap');
    }
};
