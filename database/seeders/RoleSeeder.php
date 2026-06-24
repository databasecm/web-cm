<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the nine application roles mapped onto the six hierarchy levels
 * defined by the RBAC rules (1 = Owner .. 6 = Konsumen).
 *
 * Idempotent: keyed on (name, guard_name) via updateOrCreate, so running it
 * again only refreshes the level and never duplicates rows.
 */
class RoleSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * Role name => hierarchy level.
     *
     * @var array<string, int>
     */
    private const ROLES = [
        'owner' => Role::LEVEL_OWNER,            // 1
        'direktur' => Role::LEVEL_DIREKTUR,      // 2
        'manager' => Role::LEVEL_MANAGEMENT,     // 3
        'finance' => Role::LEVEL_MANAGEMENT,     // 3
        'hr' => Role::LEVEL_MANAGEMENT,          // 3
        'mitra_pembiayaan' => Role::LEVEL_MITRA, // 4
        'supplier' => Role::LEVEL_MITRA,         // 4
        'mandor' => Role::LEVEL_MANDOR,          // 5
        'konsumen' => Role::LEVEL_KONSUMEN,      // 6
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name => $level) {
            Role::updateOrCreate(
                ['name' => $name, 'guard_name' => self::GUARD],
                ['level' => $level],
            );
        }

        // Spatie caches roles/permissions; flush so freshly seeded roles
        // resolve immediately within the same request (e.g. tests, tinker).
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
