<?php

namespace App\Models;

use App\Enums\MaterialSource;
use App\Observers\MaterialObserver;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A material in the price database (ERD §A.3). `price` is the current unit price
 * and the source of truth for AHSAP material components (ADR-0004); each change
 * is journalled to material_price_history by {@see MaterialObserver}.
 */
#[ObservedBy(MaterialObserver::class)]
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'input_by',
        'name',
        'brand',
        'unit',
        'price',
        'spec',
        'is_sni',
        'supplier_name',
        'supplier_address',
        'source',
    ];

    /**
     * Actor to attribute the next price journal entry to (set by
     * MaterialPriceService). Transient — never persisted as a column.
     */
    public ?int $priceChangedBy = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_sni' => 'boolean',
            'source' => MaterialSource::class,
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The account that entered this material (mandor / internal staff).
     */
    public function inputBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }

    /**
     * Price points over time, newest first.
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(MaterialPriceHistory::class)->latest('recorded_at');
    }
}
