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

    /**
     * The financing bank within level 4. Distinct from `supplier` (also L4):
     * only this role owns the read-only financing dashboard (CLAUDE.md §6.5).
     */
    public const NAME_MITRA_PEMBIAYAAN = 'mitra_pembiayaan';

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

    /**
     * Whether accounts with this role must carry a bidang. True only for
     * Manager and Mandor (CLAUDE.md §6.4); every other role must have none.
     * Single source of truth shared by the Form Request and Filament form.
     */
    public function requiresBidang(): bool
    {
        return in_array($this->name, [self::NAME_MANAGER, self::NAME_MANDOR], true);
    }
}
