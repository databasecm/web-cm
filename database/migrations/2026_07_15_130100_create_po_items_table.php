<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase-order line items (Fase 6-5). `unit_price` is a SNAPSHOT of the
     * material's price when the PO is created — the PO does not move when the
     * material price later changes (same snapshot discipline as the RAB). Items
     * die with their PO (cascade). BigDecimal money (ADR-0005).
     */
    public function up(): void
    {
        Schema::create('po_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2); // snapshot at PO creation
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('po_items');
    }
};
