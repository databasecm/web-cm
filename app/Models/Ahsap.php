<?php

namespace App\Models;

use App\Enums\Bidang;
use App\Models\Concerns\Auditable;
use App\Services\AhsapCalculator;
use Database\Factories\AhsapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Analisa Harga Satuan Pekerjaan (ERD §A.3) — the per-bidang unit-price analysis
 * that underpins RAB. `base_price` is calculated from its components by
 * {@see AhsapCalculator} (ADR-0004); deliberate base_price changes
 * (the resync action, Fase 2A-3) are audited via the Auditable trail.
 */
class Ahsap extends Model
{
    /** @use HasFactory<AhsapFactory> */
    use Auditable, HasFactory;

    protected $table = 'ahsap';

    protected $fillable = [
        'code',
        'name',
        'bidang',
        'unit',
        'base_price',
        'needs_review',
        'review_reason',
        'review_requested_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bidang' => Bidang::class,
            'base_price' => 'decimal:2',
            'needs_review' => 'boolean',
            'review_requested_at' => 'datetime',
        ];
    }

    /**
     * Components this analysis is built from.
     */
    public function components(): HasMany
    {
        return $this->hasMany(AhsapComponent::class);
    }
}
