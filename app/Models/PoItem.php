<?php

namespace App\Models;

use Database\Factories\PoItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase-order line (Fase 6-5). subtotal = quantity × unit_price
 * (BigDecimal). `unit_price` is a snapshot of the material price at PO creation.
 */
class PoItem extends Model
{
    /** @use HasFactory<PoItemFactory> */
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'material_id',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** The referenced material (a price/spec reference; nullable). */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
