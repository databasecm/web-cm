<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application role: extends the spatie/laravel-permission role with the
 * account hierarchy `level` (1 = Owner .. 6 = Konsumen).
 */
class Role extends SpatieRole
{
    public const LEVEL_OWNER = 1;

    public const LEVEL_DIREKTUR = 2;

    public const LEVEL_MANAGEMENT = 3; // Manager / Finance / HR

    public const LEVEL_MITRA = 4; // Mitra Pembiayaan / Supplier

    public const LEVEL_MANDOR = 5;

    public const LEVEL_KONSUMEN = 6;

    /**
     * Role name carrying account-management capability at level 3. The other
     * L3 roles (finance, hr) manage finance/HR data, not accounts.
     */
    public const NAME_MANAGER = 'manager';

    public const NAME_MANDOR = 'mandor';

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    /**
     * Users whose primary role (users.role_id) is this role.
     *
     * Distinct from the spatie `users()` relation, which resolves through
     * the model_has_roles pivot.
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
