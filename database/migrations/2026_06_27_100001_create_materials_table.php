<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Material database (ERD §A.3): supplier price lists blended with internal /
     * field (mandor) input. `price` is the current unit price; its trail lives in
     * material_price_history. `unit` is the purchase unit (sak, kg, m³, …) — a
     * material's own unit, distinct from an AHSAP's output unit.
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('input_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->text('spec')->nullable();
            $table->boolean('is_sni')->default(false);
            // Free-text store/address for field input without a registered supplier.
            $table->string('supplier_name')->nullable();
            $table->string('supplier_address')->nullable();
            $table->string('source')->default('internal');
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
