<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for the employee master (Fase 5-1, CLAUDE.md §6.4/§7).
 *
 * - Owner/Direktur: manage every employee.
 * - HR: manage every employee (needed for payroll, Fase 6).
 * - Mandor: manage employees in its OWN bidang (field data).
 * - Manager: view employees in its own bidang (read-only oversight).
 * - Mitra Pembiayaan / Konsumen: no access at all.
 */
class EmployeePolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isHR() || $actor->isManager() || $actor->isMandor();
    }

    public function view(User $actor, Employee $employee): bool
    {
        if ($this->isOverseer($actor) || $actor->isHR()) {
            return true;
        }

        // Manager and Mandor see only their own bidang.
        if ($actor->isManager() || $actor->isMandor()) {
            return $actor->bidang !== null && $actor->bidang === $employee->bidang;
        }

        return false;
    }

    /**
     * Create an employee — HR/overseers anywhere, a Mandor for its own bidang
     * (enforced at write time by setting bidang = the Mandor's). Managers do not
     * create (view-only).
     */
    public function create(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isHR() || $actor->isMandor();
    }

    public function update(User $actor, Employee $employee): bool
    {
        return $this->manages($actor, $employee);
    }

    public function delete(User $actor, Employee $employee): bool
    {
        return $this->manages($actor, $employee);
    }

    protected function manages(User $actor, Employee $employee): bool
    {
        if ($this->isOverseer($actor) || $actor->isHR()) {
            return true;
        }

        return $actor->isMandor() && $actor->bidang !== null && $actor->bidang === $employee->bidang;
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }
}
