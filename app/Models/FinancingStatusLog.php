<?php

namespace App\Models;

use App\Enums\FinancingStatus;
use Database\Factories\FinancingStatusLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a financing's status trail (ERD §A.4). Append-only; written by
 * {@see Financing::transitionTo()}.
 */
class FinancingStatusLog extends Model
{
    /** @use HasFactory<FinancingStatusLogFactory> */
    use HasFactory;

    protected $fillable = [
        'financing_id',
        'status',
        'note',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FinancingStatus::class,
        ];
    }

    public function financing(): BelongsTo
    {
        return $this->belongsTo(Financing::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
