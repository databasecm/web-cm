<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\PayrollStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records daily worker attendance (Fase 5-2) — the payroll source of truth. Pure
 * service + integrity guards; authorization is the caller's job (the
 * recordAttendance gate / AttendancePolicy).
 *
 * Guards protect payroll integrity:
 * - one attendance per worker per day (anti-double wages), enforced here AND by
 *   the DB unique key (employee_id, date);
 * - only an active worker may be attended;
 * - the worker and the project must share a bidang (no cross-bidang attendance).
 */
class AttendanceService
{
    public function record(
        Employee $employee,
        Project $project,
        string $date,
        AttendanceStatus $status,
        ?User $by = null,
        ?string $note = null,
        ?string $clientId = null,
    ): Attendance {
        // Idempotent offline sync: a retried item (same client_id) returns the
        // already-created row unchanged (wasRecentlyCreated = false) — no
        // duplicate, no error (Fase 5-4).
        if ($clientId !== null) {
            $existing = Attendance::query()->where('client_id', $clientId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($employee->status !== EmployeeStatus::Aktif) {
            throw AttendanceException::employeeInactive();
        }

        if ($employee->bidang !== $project->bidang) {
            throw AttendanceException::bidangMismatch();
        }

        // ADR-0016: a paid payroll locks its period's attendance — no new rows.
        $this->assertPeriodNotLocked($date);

        return DB::transaction(function () use ($employee, $project, $date, $status, $by, $note, $clientId): Attendance {
            $exists = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw AttendanceException::alreadyRecorded();
            }

            return Attendance::create([
                'client_id' => $clientId,
                'employee_id' => $employee->id,
                'project_id' => $project->id,
                'date' => $date,
                'status' => $status,
                'recorded_by' => $by?->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * Correct the status/note of an already-recorded attendance (mis-entry). The
     * worker/project/date are immutable — only the day's status changes — so the
     * anti-double key holds and the change is captured in the audit trail.
     *
     * (A hard correction window is deferred to payroll, Fase 6: once a week's
     * payroll is processed its attendances lock. Until then corrections are open.)
     */
    public function correct(Attendance $attendance, AttendanceStatus $status, ?User $by = null, ?string $note = null): Attendance
    {
        // ADR-0016: once the period's payroll is paid, its attendance is locked.
        $this->assertPeriodNotLocked($attendance->date->toDateString());

        $attendance->update([
            'status' => $status,
            'recorded_by' => $by?->id ?? $attendance->recorded_by,
            'note' => $note ?? $attendance->note,
        ]);

        return $attendance;
    }

    /**
     * Whether the given date falls inside a paid payroll period — such
     * attendance is frozen (ADR-0016).
     */
    public function isPeriodLocked(string $date): bool
    {
        return Payroll::query()
            ->where('status', PayrollStatus::Paid->value)
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();
    }

    private function assertPeriodNotLocked(string $date): void
    {
        if ($this->isPeriodLocked($date)) {
            throw AttendanceException::periodLocked();
        }
    }

    /**
     * Number of attended (hadir) days for a worker in a date range — the daily
     * payroll base (Fase 6): attended days × daily wage.
     */
    public function countHadir(Employee $employee, string $from, string $to): int
    {
        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('status', AttendanceStatus::Hadir->value)
            // whereDate bounds so the last day (payday Saturday = period_end) is
            // included despite the date cast's time component.
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->count();
    }
}
