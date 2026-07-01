<?php

namespace App\Models;

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\InstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment installment of a project (ERD §A.4). Generated at checkout from the
 * chosen scheme; amounts are financial, so the model is Auditable.
 */
class Installment extends Model
{
    /** @use HasFactory<InstallmentFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'project_id',
        'term_no',
        'label',
        'percentage',
        'amount',
        'due_condition',
        'status',
        'va_number',
        'gateway_ref',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'amount' => 'decimal:2',
            'due_condition' => DueCondition::class,
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
