<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Enums\PayrollStatus;
use App\Enums\PayrollType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates weekly daily-worker payroll (Fase 6-1). Pure service + guards;
 * authorization is the caller's job (generatePayroll gate — HR/overseers, NOT
 * Finance, who pays in 6-2).
 *
 * For each ACTIVE DAILY worker with attendance in the Mon–Sat period:
 *   days_present = attended (hadir) days   [izin/alpa never count]
 *   gross        = days_present × daily_wage   (BigDecimal, exact)
 *   net          = gross − deductions (0 here)
 *
 * Idempotent: one payroll per (period, type). Re-generating a DRAFT refreshes its
 * payslips (picks up new attendance); an already approved/paid run is returned
 * untouched. No payment/cash posting here — that is Fase 6-2.
 */
class PayrollService
{
    public function __construct(private AttendanceService $attendance) {}

    public function generate(string $periodStart, string $periodEnd, ?int $by = null): Payroll
    {
        return DB::transaction(function () use ($periodStart, $periodEnd): Payroll {
            // whereDate so the match ignores any time component on the date cast.
            $payroll = Payroll::query()
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->where('type', PayrollType::WeeklyDaily->value)
                ->first();

            // Never re-touch a run that has left draft (approved/paid is locked).
            if ($payroll !== null && $payroll->status !== PayrollStatus::Draft) {
                return $payroll->load('payslips');
            }

            if ($payroll === null) {
                $payroll = Payroll::create([
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'type' => PayrollType::WeeklyDaily->value,
                    'status' => PayrollStatus::Draft->value,
                ]);
            } else {
                // Re-generate a draft from scratch — idempotent, never doubled.
                $payroll->payslips()->delete();
            }

            foreach ($this->workersInPeriod($periodStart, $periodEnd) as $employee) {
                $days = $this->attendance->countHadir($employee, $periodStart, $periodEnd);
                $gross = BigDecimal::of((string) $days)
                    ->multipliedBy((string) $employee->daily_wage)
                    ->toScale(2, RoundingMode::HALF_UP);
                $deductions = BigDecimal::zero()->toScale(2);
                $net = $gross->minus($deductions)->toScale(2, RoundingMode::HALF_UP);

                $payroll->payslips()->create([
                    'employee_id' => $employee->id,
                    'days_present' => $days,
                    'daily_wage' => (string) $employee->daily_wage,
                    'gross' => (string) $gross,
                    'deductions' => (string) $deductions,
                    'net' => (string) $net,
                ]);
            }

            return $payroll->load('payslips');
        });
    }

    /**
     * Active DAILY workers who have any attendance recorded in the period — the
     * set on this weekly run.
     *
     * @return Collection<int, Employee>
     */
    private function workersInPeriod(string $periodStart, string $periodEnd)
    {
        $ids = Attendance::query()
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->distinct()
            ->pluck('employee_id');

        return Employee::query()
            ->whereIn('id', $ids)
            ->where('type', EmployeeType::Harian->value)
            ->where('status', EmployeeStatus::Aktif->value)
            ->get();
    }
}
