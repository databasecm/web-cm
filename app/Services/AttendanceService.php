<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Employee;
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
    ): Attendance {
        if ($employee->status !== EmployeeStatus::Aktif) {
            throw AttendanceException::employeeInactive();
        }

        if ($employee->bidang !== $project->bidang) {
            throw AttendanceException::bidangMismatch();
        }

        return DB::transaction(function () use ($employee, $project, $date, $status, $by, $note): Attendance {
            $exists = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw AttendanceException::alreadyRecorded();
            }

            return Attendance::create([
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
        $attendance->update([
            'status' => $status,
            'recorded_by' => $by?->id ?? $attendance->recorded_by,
            'note' => $note ?? $attendance->note,
        ]);

        return $attendance;
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
            ->whereBetween('date', [$from, $to])
            ->count();
    }
}
