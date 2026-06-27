<?php

namespace App\Models;

use App\Enums\AhsapComponentType;
use App\Observers\AhsapComponentObserver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\AhsapComponentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of an AHSAP analysis (ERD §A.3). `unit_price` is a snapshot: for a
 * material component it is copied from Material.price when set/synced (ADR-0004),
 * not joined live; for upah/alat it is entered directly.
 */
#[ObservedBy(AhsapComponentObserver::class)]
class AhsapComponent extends Model
{
    /** @use HasFactory<AhsapComponentFactory> */
    use HasFactory;

    protected $fillable = [
        'ahsap_id',
        'type',
        'material_id',
        'description',
        'coefficient',
        'unit_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AhsapComponentType::class,
            'coefficient' => 'decimal:4',
            'unit_price' => 'decimal:2',
        ];
    }

    public function ahsap(): BelongsTo
    {
        return $this->belongsTo(Ahsap::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * This line's contribution to the AHSAP base price: coefficient × unit_price.
     */
    public function lineTotal(): string
    {
        return (string) BigDecimal::of((string) $this->coefficient)
            ->multipliedBy((string) $this->unit_price)
            ->toScale(2, RoundingMode::HALF_UP);
    }
}
