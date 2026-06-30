<?php

namespace App\Models;

use Database\Factories\RabItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line item of a RAB (ERD §A.2). description/unit/unit_price are SNAPSHOTS from
 * the source AHSAP at build time (ADR-0007); `ahsap_id` is provenance only.
 */
class RabItem extends Model
{
    /** @use HasFactory<RabItemFactory> */
    use HasFactory;

    protected $fillable = [
        'rab_id',
        'ahsap_id',
        'description',
        'unit',
        'volume',
        'unit_price',
        'subtotal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'volume' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function rab(): BelongsTo
    {
        return $this->belongsTo(Rab::class);
    }

    public function ahsap(): BelongsTo
    {
        return $this->belongsTo(Ahsap::class);
    }
}
