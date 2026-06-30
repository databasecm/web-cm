<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * The narrow, context-bound authorization for the deal forward-creations
 * (ADR-0001, ADR-0003): turning a consultation deal into a consumer account, and
 * turning a deal into a draft project.
 *
 * Deliberately separate from {@see UserPolicy} and {@see ProjectPolicy}: each is
 * a SINGLE forward-creation ability tied to a deal, and never widens the general
 * account-management hierarchy. A Manager still cannot view/update/delete
 * consumer (no-bidang) accounts through UserPolicy — including one it just
 * created (ongoing rights = none).
 */
class DealPolicy
{
    /**
     * Consultation-handling staff for a given bidang: Owner/Direktur anywhere, a
     * Manager only in its own. Finance/HR/Mitra/Mandor/Konsumen never qualify.
     */
    protected function handlesDealIn(User $actor, ?string $bidang): bool
    {
        if (in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true)) {
            return true;
        }

        return $actor->isManager() && $bidang !== null && $actor->bidang?->value === $bidang;
    }

    /**
     * Who may create a consumer account for a deal in the given bidang.
     */
    public function createCustomer(User $actor, ?string $bidang): bool
    {
        return $this->handlesDealIn($actor, $bidang);
    }

    /**
     * Who may create a draft project for a deal in the given bidang. Same narrow,
     * deal-bound gate — it creates a project, never an account, so it touches no
     * account-management right.
     */
    public function createProject(User $actor, ?string $bidang): bool
    {
        return $this->handlesDealIn($actor, $bidang);
    }
}
