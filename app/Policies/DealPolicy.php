<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * The narrow, context-bound authorization for converting a consultation deal
 * into a consumer account (ADR-0001, ADR-0003).
 *
 * Deliberately separate from {@see UserPolicy}: it grants a SINGLE
 * forward-creation ability tied to a deal, and never widens the general
 * account-management hierarchy. A Manager still cannot view/update/delete
 * consumer (no-bidang) accounts through UserPolicy — including the very account
 * it just created (ongoing rights = none).
 */
class DealPolicy
{
    /**
     * Who may create a consumer account for a deal in the given bidang:
     * consultation-handling staff only — Owner and Direktur for any bidang, a
     * Manager confined to its own. Finance/HR/Mitra/Mandor/Konsumen never can.
     */
    public function createCustomer(User $actor, ?string $bidang): bool
    {
        if (in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true)) {
            return true;
        }

        if (! $actor->isManager()) {
            return false;
        }

        // A Manager may only convert deals within its own business unit.
        return $bidang !== null && $actor->bidang?->value === $bidang;
    }
}
