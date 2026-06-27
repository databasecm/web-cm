<?php

use App\Enums\Bidang;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function matActor(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

it('lets every internal account view materials, including Mandor', function () {
    foreach (['owner', 'direktur', 'manager', 'finance', 'hr', 'mandor'] as $name) {
        $actor = matActor($name, in_array($name, ['manager', 'mandor'], true) ? Bidang::Cufid : null);
        expect($actor->can('viewAny', Material::class))->toBeTrue("{$name} should view materials");
    }
});

it('denies Mitra and Konsumen any access to materials', function () {
    foreach (['mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $actor = matActor($name);
        expect($actor->can('viewAny', Material::class))->toBeFalse("{$name} must not view")
            ->and($actor->can('create', Material::class))->toBeFalse();
    }
});

it('lets only Owner, Direktur and Manager manage materials', function () {
    $material = Material::factory()->create();

    foreach (['owner', 'direktur'] as $name) {
        $actor = matActor($name);
        expect($actor->can('create', Material::class))->toBeTrue()
            ->and($actor->can('update', $material))->toBeTrue()
            ->and($actor->can('delete', $material))->toBeTrue();
    }

    $manager = matActor('manager', Bidang::Cufid);
    expect($manager->can('create', Material::class))->toBeTrue()
        ->and($manager->can('update', $material))->toBeTrue();

    // Finance/HR/Mandor can view but never manage.
    foreach (['finance', 'hr', 'mandor'] as $name) {
        $actor = matActor($name, $name === 'mandor' ? Bidang::Cufid : null);
        expect($actor->can('create', Material::class))->toBeFalse("{$name} must not create")
            ->and($actor->can('update', $material))->toBeFalse("{$name} must not update")
            ->and($actor->can('delete', $material))->toBeFalse("{$name} must not delete");
    }
});
