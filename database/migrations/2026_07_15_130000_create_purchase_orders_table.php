<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Material purchase orders (Fase 6-5). A PO is a header + line items; the
     * material expense hits the cash book only when the PO is RECEIVED (goods in
     * hand = the realised cash-out), tagged to project_id for per-project P&L
     * (Fase 6-3b). Auditable (financial). nullOnDelete keeps a PO's audit intact
     * if its project/supplier is removed.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->nullable()->unique();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('status')->default('draft'); // draft|ordered|received|cancelled
            $table->decimal('total', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
