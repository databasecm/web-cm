<?php

namespace App\Services;

use App\Enums\EmployeeStatusChangeType;
use App\Models\Employee;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Employee position/wage changes with an append-only history (Fase 5-1). Pure
 * service + guards; authorization is the caller's job (EmployeePolicy). Each
 * change records an employee_status_logs row so HR has a paper trail.
 */
class EmployeeService
{
    /**
     * Change the employee's position (promotion), logging old → new.
     */
    public function changePosition(Employee $employee, string $newPosition, ?User $by = null, ?string $effectiveDate = null): Employee
    {
        $old = $employee->position;

        return DB::transaction(function () use ($employee, $newPosition, $old, $by, $effectiveDate): Employee {
            $employee->update(['position' => $newPosition]);

            $employee->statusLogs()->create([
                'change_type' => EmployeeStatusChangeType::Promotion,
                'old_value' => $old,
                'new_value' => $newPosition,
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'created_by' => $by?->id,
            ]);

            return $employee;
        });
    }

    /**
     * Change the employee's daily wage (salary change), logging old → new.
     */
    public function changeWage(Employee $employee, string|float $newWage, ?User $by = null, ?string $effectiveDate = null): Employee
    {
        $old = $employee->daily_wage;
        $normalized = (string) BigDecimal::of((string) $newWage)->toScale(2, RoundingMode::HALF_UP);

        return DB::transaction(function () use ($employee, $normalized, $old, $by, $effectiveDate): Employee {
            $employee->update(['daily_wage' => $normalized]);

            $employee->statusLogs()->create([
                'change_type' => EmployeeStatusChangeType::Salary,
                'old_value' => (string) $old,
                'new_value' => $normalized,
                'effective_date' => $effectiveDate ?? now()->toDateString(),
                'created_by' => $by?->id,
            ]);

            return $employee;
        });
    }
}
