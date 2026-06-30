<?php

namespace App\Models;

use App\Enums\RabStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\RabFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A RAB — the frozen quote built from AHSAP (ERD §A.2, ADR-0004/0007). Per-project
 * versioned; totals and the margin/PPN/overhead rates are snapshotted at build so
 * the quote never moves when AHSAP prices or the global settings change later.
 */
class Rab extends Model
{
    /** @use HasFactory<RabFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'project_id',
        'version',
        'total_material',
        'total_upah',
        'overhead_percent',
        'overhead',
        'margin_percent',
        'margin',
        'ppn_percent',
        'ppn',
        'grand_total',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RabStatus::class,
            'total_material' => 'decimal:2',
            'total_upah' => 'decimal:2',
            'overhead_percent' => 'decimal:4',
            'overhead' => 'decimal:2',
            'margin_percent' => 'decimal:4',
            'margin' => 'decimal:2',
            'ppn_percent' => 'decimal:4',
            'ppn' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RabItem::class);
    }

    public function isApproved(): bool
    {
        return $this->status === RabStatus::Approved;
    }
}
