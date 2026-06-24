<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Enforces the account-management hierarchy hard rules (CLAUDE.md §6).
 *
 * The single source of truth is {@see self::canManage()}: an actor may act on a
 * target only when the actor carries management capability, strictly outranks
 * the target, and — when the actor is bidang-scoped — shares the target's
 * business unit. Owner protection and the self-action ban are layered on top.
 */
class UserPolicy
{
    /**
     * Core hierarchy gate shared by view/update/delete.
     *
     * Rules applied (CLAUDE.md §6.3–§6.4):
     * - Actor must carry account-management capability (L1/L2/L3 only).
     * - Actor must strictly outrank the target (smaller level number).
     * - A bidang-scoped actor (Manager with a bidang) may only reach targets
     *   in the same bidang. Company-wide L3 (Finance/HR, no bidang) is not
     *   bidang-restricted.
     */
    protected function canManage(User $actor, User $target): bool
    {
        if (! $actor->canManageAccounts()) {
            return false;
        }

        if (! $actor->outranks($target)) {
            return false;
        }

        if ($actor->isBidangScoped() && $actor->bidang !== $target->bidang) {
            return false;
        }

        return true;
    }

    /**
     * Who may browse the account list: any account with management capability.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->canManageAccounts();
    }

    /**
     * An account may always view itself; otherwise the hierarchy gate applies.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $this->canManage($actor, $target);
    }

    /**
     * Who may create accounts: management-capable actors. The specific role and
     * bidang they may assign is enforced by the store Form Request.
     */
    public function create(User $actor): bool
    {
        return $actor->canManageAccounts();
    }

    /**
     * Who may update a given account. Self-service profile edits are out of
     * scope here; this gate governs hierarchical account management only.
     */
    public function update(User $actor, User $target): bool
    {
        return $this->canManage($actor, $target);
    }

    /**
     * Who may delete a given account.
     *
     * Hard rules (CLAUDE.md §6.1–§6.2) layered before the hierarchy gate:
     * - A protected Owner can never be deleted by anyone.
     * - No account may delete itself.
     */
    public function delete(User $actor, User $target): bool
    {
        if ($target->is_protected) {
            return false;
        }

        if ($actor->is($target)) {
            return false;
        }

        return $this->canManage($actor, $target);
    }

    /**
     * Restoring a soft-deleted account follows the same hierarchy gate as
     * deletion (minus the self-action case, which cannot arise on a trashed
     * account).
     */
    public function restore(User $actor, User $target): bool
    {
        return $this->canManage($actor, $target);
    }

    /**
     * Whether the actor may assign the given role + bidang to an account.
     *
     * Used by the store/update Form Requests so an over-rank or cross-bidang
     * assignment is rejected as a forbidden action (403), consistent with the
     * delete/update gates. A null role means the shape is still being
     * validated elsewhere — defer rather than block here.
     */
    public function assign(User $actor, ?Role $role, ?string $bidang): bool
    {
        if (! $actor->canManageAccounts()) {
            return false;
        }

        if ($role === null || $role->level === null || $actor->level() === null) {
            return false;
        }

        // The assigned role must sit strictly below the actor in the hierarchy.
        if ($role->level <= $actor->level()) {
            return false;
        }

        // A bidang-scoped actor may only assign accounts within its own bidang.
        if ($actor->isBidangScoped() && $bidang !== $actor->bidang?->value) {
            return false;
        }

        return true;
    }
}
