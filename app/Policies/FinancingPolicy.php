<?php

namespace App\Policies;

use App\Models\Financing;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for financing applications (Fase 4-1, CLAUDE.md §6.5).
 *
 * THE KEY SEPARATION (keputusan B): a bank partner may WRITE the lifecycle of
 * its OWN financings (approve/reject/request docs/interview), but remains
 * strictly READ-ONLY on projects and operational data. That project read-only
 * rule lives in {@see ProjectPolicy} and is untouched here — the financing write
 * grant must never leak into project mutations.
 *
 * - View: Owner/Direktur (all), Finance (all, cash oversight), a Manager in its
 *   bidang, the owning consumer, and the financing bank (its own).
 * - Apply: the owning consumer (their project). Full flow in Fase 4-5.
 * - Manage lifecycle: the financing bank (its own) or Owner/Direktur. Nobody
 *   else — not the consumer, not a Manager.
 */
class FinancingPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor)
            || $actor->isFinance()
            || $actor->isManager()
            || $actor->level() === Role::LEVEL_KONSUMEN
            || $actor->isBankMitra();
    }

    public function view(User $actor, Financing $financing): bool
    {
        if ($this->isOverseer($actor) || $actor->isFinance()) {
            return true;
        }

        if ($actor->isManager()) {
            return $actor->bidang !== null && $actor->bidang === $financing->project?->bidang;
        }

        return $this->ownsApplication($actor, $financing) || $this->banks($actor, $financing);
    }

    /**
     * Apply for financing on a project — the owning consumer only.
     */
    public function apply(User $actor, Project $project): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $project->konsumen_id === (int) $actor->getKey();
    }

    /**
     * Write the financing lifecycle (status transitions, doc review). The
     * financing's own bank, or an overseer. A bank may only touch ITS financing —
     * never another bank's, and never a project.
     */
    public function manageLifecycle(User $actor, Financing $financing): bool
    {
        return $this->isOverseer($actor) || $this->banks($actor, $financing);
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    protected function ownsApplication(User $actor, Financing $financing): bool
    {
        return $actor->level() === Role::LEVEL_KONSUMEN
            && (int) $financing->konsumen_id === (int) $actor->getKey();
    }

    protected function banks(User $actor, Financing $financing): bool
    {
        return $actor->isBankMitra()
            && $financing->bank_mitra_id !== null
            && (int) $financing->bank_mitra_id === (int) $actor->getKey();
    }
}
