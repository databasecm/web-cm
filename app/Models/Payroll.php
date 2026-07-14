<?php

namespace App\Models;

use App\Enums\PayrollStatus;
use App\Enums\PayrollType;
use App\Models\Concerns\Auditable;
use Database\Factories\PayrollFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payroll run for a period (ERD §A.5). A weekly_daily run pays daily workers
 * for a Mon–Sat period on the Saturday. Auditable (payroll is money). Generation
 * (Fase 6-1) builds draft payslips; payment (Fase 6-2) posts the cash-book
 * expense and locks the period's attendance (ADR-0016).
 */
class Payroll extends Model
{
    /** @use HasFactory<PayrollFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'period_start',
        'period_end',
        'type',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'type' => PayrollType::class,
            'status' => PayrollStatus::class,
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
