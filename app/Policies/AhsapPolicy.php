<?php

namespace App\Policies;

use App\Models\Ahsap;
use App\Models\Role;
use App\Models\User;

/**
 * Authorization for Master AHSAP (konsep §7, ADR-0004 / Fase 2A-2).
 *
 * View is cross-bidang for every internal account (shared master data, incl.
 * Mandor). Manage is bidang-scoped: Owner/Direktur span every unit, a Manager
 * only its own (§6.4). Finance/HR/Mandor may view but never manage; Mitra and
 * Konsumen have no access.
 */
class AhsapPolicy
{
    /**
     * Internal staff = levels 1–3 and Mandor (5); excludes Mitra (4) & Konsumen (6).
     */
    protected function isInternal(User $actor): bool
    {
        return in_array($actor->level(), [
            Role::LEVEL_OWNER,
            Role::LEVEL_DIREKTUR,
            Role::LEVEL_MANAGEMENT,
            Role::LEVEL_MANDOR,
        ], true);
    }

    protected function isOverseer(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true);
    }

    /**
     * Manage a specific record: overseers anywhere; a Manager only in its bidang.
     */
    protected function canManageRecord(User $actor, Ahsap $ahsap): bool
    {
        if ($this->isOverseer($actor)) {
            return true;
        }

        return $actor->isManager() && $actor->bidang === $ahsap->bidang;
    }

    public function viewAny(User $actor): bool
    {
        return $this->isInternal($actor);
    }

    public function view(User $actor, Ahsap $ahsap): bool
    {
        // Cross-bidang: the AHSAP master is shared reading for all internal staff.
        return $this->isInternal($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isOverseer($actor) || $actor->isManager();
    }

    public function update(User $actor, Ahsap $ahsap): bool
    {
        return $this->canManageRecord($actor, $ahsap);
    }

    public function delete(User $actor, Ahsap $ahsap): bool
    {
        return $this->canManageRecord($actor, $ahsap);
    }

    public function restore(User $actor, Ahsap $ahsap): bool
    {
        return $this->canManageRecord($actor, $ahsap);
    }
}
