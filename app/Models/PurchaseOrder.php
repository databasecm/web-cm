<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A material purchase order (Fase 6-5) — header for a set of line items. The
 * material expense is posted to the cash book only on RECEIVE (goods in hand),
 * tagged to the project for per-project P&L. Auditable (financial).
 */
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'po_number',
        'project_id',
        'supplier_id',
        'status',
        'total',
        'note',
        'ordered_by',
        'received_by',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'total' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PoItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The account that created/ordered the PO. */
    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /** The Finance/overseer account that received it (posted the expense). */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
