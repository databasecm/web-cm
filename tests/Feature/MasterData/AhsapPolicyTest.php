<?php

use App\Enums\Bidang;
use App\Models\Ahsap;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function ahsapActor(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// View — cross-bidang for all internal staff (incl. Mandor)
// ---------------------------------------------------------------------------

it('lets every internal account view AHSAP across bidang', function () {
    $cufid = Ahsap::factory()->inBidang(Bidang::Cufid)->create();
    $cc = Ahsap::factory()->inBidang(Bidang::Cc)->create();

    foreach (['owner', 'direktur', 'manager', 'finance', 'hr', 'mandor'] as $name) {
        $actor = ahsapActor($name, in_array($name, ['manager', 'mandor'], true) ? Bidang::Cufid : null);
        expect($actor->can('viewAny', Ahsap::class))->toBeTrue("{$name} viewAny")
            // a Cufid Manager can still VIEW a CC analysis (shared master)
            ->and($actor->can('view', $cc))->toBeTrue("{$name} view cross-bidang")
            ->and($actor->can('view', $cufid))->toBeTrue();
    }
});

it('denies Mitra and Konsumen any access to AHSAP', function () {
    $ahsap = Ahsap::factory()->create();

    foreach (['mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $actor = ahsapActor($name);
        expect($actor->can('viewAny', Ahsap::class))->toBeFalse("{$name} viewAny")
            ->and($actor->can('view', $ahsap))->toBeFalse("{$name} view");
    }
});

// ---------------------------------------------------------------------------
// Manage — bidang-scoped (§6.4)
// ---------------------------------------------------------------------------

it('scopes a Manager to managing only its own bidang AHSAP', function () {
    $managerCufid = ahsapActor('manager', Bidang::Cufid);
    $cufid = Ahsap::factory()->inBidang(Bidang::Cufid)->create();
    $cc = Ahsap::factory()->inBidang(Bidang::Cc)->create();

    expect($managerCufid->can('create', Ahsap::class))->toBeTrue()
        ->and($managerCufid->can('update', $cufid))->toBeTrue()
        ->and($managerCufid->can('delete', $cufid))->toBeTrue()
        // cross-bidang manage → denied (but view is allowed, tested above)
        ->and($managerCufid->can('update', $cc))->toBeFalse()
        ->and($managerCufid->can('delete', $cc))->toBeFalse();
});

it('lets Owner and Direktur manage AHSAP in every bidang', function () {
    $cc = Ahsap::factory()->inBidang(Bidang::Cc)->create();

    foreach (['owner', 'direktur'] as $name) {
        $actor = ahsapActor($name);
        expect($actor->can('create', Ahsap::class))->toBeTrue()
            ->and($actor->can('update', $cc))->toBeTrue()
            ->and($actor->can('delete', $cc))->toBeTrue();
    }
});

it('lets Finance, HR and Mandor view but never manage AHSAP', function () {
    $ahsap = Ahsap::factory()->inBidang(Bidang::Cufid)->create();

    foreach (['finance', 'hr', 'mandor'] as $name) {
        $actor = ahsapActor($name, $name === 'mandor' ? Bidang::Cufid : null);
        expect($actor->can('create', Ahsap::class))->toBeFalse("{$name} create")
            ->and($actor->can('update', $ahsap))->toBeFalse("{$name} update")
            ->and($actor->can('delete', $ahsap))->toBeFalse("{$name} delete");
    }
});
