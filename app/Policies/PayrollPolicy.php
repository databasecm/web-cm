<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for payroll (Fase 6-1, CLAUDE.md §6.3/§7).
 *
 * Segregation of duties: HR (and overseers) GENERATE payroll; Finance does NOT
 * generate — Finance pays it (posts the cash expense, Fase 6-2), consistent with
 * recordPayment. View is open to HR, Finance and overseers.
 */
class PayrollPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isHR() || $actor->isFinance();
    }

    public function view(User $actor, Payroll $payroll): bool
    {
        return $this->viewAny($actor);
    }

    /**
     * Generate/regenerate a payroll run — HR or an overseer. Exposed via the
     * generatePayroll gate (no Payroll instance yet).
     */
    public function generate(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isHR();
    }

    /**
     * Pay a payroll run (posts the cash expense) — Finance or an overseer. HR
     * does NOT pay (segregation of duties, §6.3 — HR only generates). Exposed via
     * the payPayroll gate.
     */
    public function pay(User $actor, Payroll $payroll): bool
    {
        return $this->isOverseer($actor) || $actor->isFinance();
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }
}
