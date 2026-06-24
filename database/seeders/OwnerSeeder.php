<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the single protected Owner account (level 1, is_protected = true).
 *
 * Credentials come from config('cm.owner.*') (sourced from the environment) so
 * no secret is ever committed. Idempotent: keyed on email via updateOrCreate,
 * so re-running refreshes the account in place instead of duplicating it.
 *
 * Requires the `owner` role to exist first — run RoleSeeder before this.
 */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $name = config('cm.owner.name');
        $email = config('cm.owner.email');
        $password = config('cm.owner.password');

        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'OWNER_EMAIL and OWNER_PASSWORD must be set in the environment before seeding the Owner account.'
            );
        }

        $ownerRole = Role::where('name', 'owner')->where('guard_name', 'web')->first();

        if ($ownerRole === null) {
            throw new RuntimeException('The "owner" role is missing. Run RoleSeeder before OwnerSeeder.');
        }

        $owner = User::withTrashed()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password, // hashed by the User `password` cast
                'role_id' => $ownerRole->id,
                'is_protected' => true,
                'email_verified_at' => now(),
                'deleted_at' => null,
            ],
        );

        // Keep spatie's role assignment in sync with the primary role_id so
        // hasRole('owner') works alongside the hierarchy level.
        if (! $owner->hasRole($ownerRole)) {
            $owner->assignRole($ownerRole);
        }
    }
}
