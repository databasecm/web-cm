<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Authorization for supplier profiles (Fase 2A-4). Mirrors MaterialPolicy:
 * every internal account (incl. Mandor) may view; only Owner/Direktur/Manager
 * manage; Mitra and Konsumen have no access. The supplier self-service portal
 * arrives in a later phase.
 */
class SupplierPolicy
{
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
}
