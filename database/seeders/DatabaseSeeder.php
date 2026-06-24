<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: roles must exist before the Owner account is assigned one.
     * Both seeders are idempotent and safe to re-run.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            OwnerSeeder::class,
        ]);
    }
}
