<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Authorization for the material database (konsep §7, Fase 2A).
 *
 * View: every internal account, including Mandor (the shared master data).
 * Manage: Owner, Direktur and Manager only. Materials carry no bidang, so a
 * Manager manages all of them. Mitra (4) and Konsumen (6) have no access at all
 * — the supplier self-service and mandor field-input write paths arrive in
 * later phases (Fase 5/6).
 */
class MaterialPolicy
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

    protected function canManage(User $actor): bool
    {
        return in_array($actor->level(), [Role::LEVEL_OWNER, Role::LEVEL_DIREKTUR], true)
            || $actor->isManager();
    }

    public function viewAny(User $actor): bool
    {
        return $this->isInternal($actor);
    }

    public function view(User $actor): bool
    {
        return $this->isInternal($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function delete(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function restore(User $actor): bool
    {
        return $this->canManage($actor);
    }
}
