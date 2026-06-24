<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cm.owner.name' => 'CM Owner',
        'cm.owner.email' => 'owner@cimandiri.test',
        'cm.owner.password' => 'rahasia-owner',
    ]);
});

it('seeds nine roles across the six hierarchy levels', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe(9)
        ->and(Role::where('name', 'owner')->value('level'))->toBe(Role::LEVEL_OWNER)
        ->and(Role::where('name', 'mitra_pembiayaan')->value('level'))->toBe(Role::LEVEL_MITRA)
        ->and(Role::where('name', 'konsumen')->value('level'))->toBe(Role::LEVEL_KONSUMEN)
        ->and(Role::whereIn('name', ['manager', 'finance', 'hr'])->pluck('level')->unique()->all())
        ->toBe([Role::LEVEL_MANAGEMENT]);
});

it('is idempotent: re-running the role seeder neither duplicates nor drops roles', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe(9);
});

it('seeds one protected Owner account at level 1', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(OwnerSeeder::class);

    $owner = User::where('email', 'owner@cimandiri.test')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->is_protected)->toBeTrue()
        ->and($owner->level())->toBe(Role::LEVEL_OWNER)
        ->and($owner->hasRole('owner'))->toBeTrue()
        ->and(Hash::check('rahasia-owner', $owner->password))->toBeTrue();
});

it('is idempotent: re-running the owner seeder keeps a single account', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(OwnerSeeder::class);
    $this->seed(OwnerSeeder::class);

    expect(User::where('email', 'owner@cimandiri.test')->count())->toBe(1);
});

it('aborts when Owner credentials are not configured', function () {
    config(['cm.owner.email' => null, 'cm.owner.password' => null]);

    $this->seed(RoleSeeder::class);

    expect(fn () => $this->seed(OwnerSeeder::class))
        ->toThrow(RuntimeException::class);
});
