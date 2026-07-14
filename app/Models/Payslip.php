<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PayslipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One worker's payslip within a payroll run (ERD §A.5). gross = days_present ×
 * daily_wage; net = gross − deductions (BigDecimal). Auditable.
 */
class Payslip extends Model
{
    /** @use HasFactory<PayslipFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'payroll_id',
        'employee_id',
        'days_present',
        'daily_wage',
        'gross',
        'deductions',
        'net',
        'slip_file',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_present' => 'integer',
            'daily_wage' => 'decimal:2',
            'gross' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net' => 'decimal:2',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
