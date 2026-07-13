<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for attendance (Fase 5-2, CLAUDE.md §6.4).
 *
 * - Mandor: record/correct/view attendance in its OWN bidang (the field role).
 * - HR + Owner/Direktur: full access (payroll recap).
 * - Manager: view its own bidang (read-only).
 * - Mitra Pembiayaan / Konsumen: no access.
 */
class AttendancePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isHR() || $actor->isManager() || $actor->isMandor();
    }

    public function view(User $actor, Attendance $attendance): bool
    {
        if ($this->isOverseer($actor) || $actor->isHR()) {
            return true;
        }

        if ($actor->isManager() || $actor->isMandor()) {
            return $actor->bidang !== null && $actor->bidang === $attendance->employee?->bidang;
        }

        return false;
    }

    /**
     * Record attendance for a worker (no Attendance instance yet) — a Mandor in
     * the worker's bidang, or HR/overseers. Exposed via the recordAttendance gate.
     */
    public function record(User $actor, Employee $employee): bool
    {
        if ($this->isOverseer($actor) || $actor->isHR()) {
            return true;
        }

        return $actor->isMandor() && $actor->bidang !== null && $actor->bidang === $employee->bidang;
    }

    /** Correct an existing attendance — same rule as recording. */
    public function update(User $actor, Attendance $attendance): bool
    {
        if ($this->isOverseer($actor) || $actor->isHR()) {
            return true;
        }

        return $actor->isMandor() && $actor->bidang !== null && $actor->bidang === $attendance->employee?->bidang;
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }
}
