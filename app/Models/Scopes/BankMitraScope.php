<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only, own-project-only scope for Mitra Pembiayaan / Supplier accounts
 * (CLAUDE.md §6.5): a bank partner may only ever see projects whose
 * `bank_mitra_id` equals their own account id; every other project is closed
 * off entirely.
 *
 * SKELETON: the `projects` table does not exist yet (later milestone). When the
 * Project model lands it should opt in with:
 *
 *     #[ScopedBy(BankMitraScope::class)]
 *     class Project extends Model { ... }
 *
 * The constraint pattern lives here now so the access rule is fixed and
 * unit-tested ahead of the table.
 */
class BankMitraScope implements Scope
{
    /**
     * Column on the scoped model that references the bank partner account.
     */
    public const FOREIGN_KEY = 'bank_mitra_id';

    /**
     * Apply the scope to the model's default query for the current user.
     */
    public function apply(Builder $builder, Model $model): void
    {
        self::constrainFor($builder, Auth::user());
    }

    /**
     * Constrain a query to the bank partner's own projects.
     *
     * Non-bank actors are left untouched (their visibility is governed by other
     * rules). Extracted as a static helper so it can be reused and unit-tested
     * directly against a query builder without an authenticated request.
     */
    public static function constrainFor(Builder $builder, ?Authenticatable $user): Builder
    {
        if ($user instanceof User && $user->isBankMitra()) {
            $builder->where(self::FOREIGN_KEY, $user->getKey());
        }

        return $builder;
    }
}
