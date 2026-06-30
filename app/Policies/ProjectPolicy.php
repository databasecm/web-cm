<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for projects (Fase 2B-1, CLAUDE.md §6.4–§6.5).
 *
 * - Owner/Direktur: manage every project.
 * - Manager: manage projects in its own bidang.
 * - Konsumen: view its own projects (their channel is the Sanctum API).
 * - Mitra Pembiayaan (L4): view its own financed projects, READ-ONLY — the
 *   BankMitraScope already filters queries; this policy denies every mutation.
 *
 * (Finance/HR project access for billing/oversight arrives with the
 * installments/cash modules in a later phase.)
 */
class ProjectPolicy
{
    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    /**
     * Internal staff who may manage a given project: overseers anywhere, a
     * Manager only in its own bidang.
     */
    protected function canManageRecord(User $actor, Project $project): bool
    {
        if ($this->isOverseer($actor)) {
            return true;
        }

        return $actor->isManager() && $actor->bidang === $project->bidang;
    }

    protected function owns(User $actor, Project $project): bool
    {
        return (int) $project->konsumen_id === (int) $actor->getKey();
    }

    protected function finances(User $actor, Project $project): bool
    {
        return $actor->isBankMitra()
            && $project->bank_mitra_id !== null
            && (int) $project->bank_mitra_id === (int) $actor->getKey();
    }

    public function viewAny(User $actor): bool
    {
        return $this->isOverseer($actor)
            || $actor->isManager()
            || $actor->level() === Role::LEVEL_KONSUMEN
            || $actor->isBankMitra();
    }

    public function view(User $actor, Project $project): bool
    {
        return $this->canManageRecord($actor, $project)
            || $this->owns($actor, $project)
            || $this->finances($actor, $project);
    }

    public function create(User $actor): bool
    {
        // Created via the deal→project bridge / by management; bidang is enforced
        // there. Bank, consumer and other roles never create.
        return $this->isOverseer($actor) || $actor->isManager();
    }

    public function update(User $actor, Project $project): bool
    {
        return $this->canManageRecord($actor, $project);
    }

    /**
     * Checkout (choose scheme + generate installments) is the owning consumer's
     * action. State guards (approved RAB, no double checkout) live in
     * CheckoutService.
     */
    public function checkout(User $actor, Project $project): bool
    {
        return $this->owns($actor, $project);
    }

    public function delete(User $actor, Project $project): bool
    {
        return $this->canManageRecord($actor, $project);
    }
}
