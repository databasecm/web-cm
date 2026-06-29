<?php

use App\Enums\Bidang;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function projUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// Manage — bidang scoped (§6.4)
// ---------------------------------------------------------------------------

it('lets Owner and Direktur manage every project', function () {
    $project = Project::factory()->inBidang(Bidang::Cc)->create();

    foreach (['owner', 'direktur'] as $name) {
        $actor = projUser($name);
        expect($actor->can('view', $project))->toBeTrue()
            ->and($actor->can('update', $project))->toBeTrue()
            ->and($actor->can('delete', $project))->toBeTrue()
            ->and($actor->can('create', Project::class))->toBeTrue();
    }
});

it('scopes a Manager to projects in its own bidang', function () {
    $managerCufid = projUser('manager', Bidang::Cufid);
    $cufid = Project::factory()->inBidang(Bidang::Cufid)->create();
    $cc = Project::factory()->inBidang(Bidang::Cc)->create();

    expect($managerCufid->can('view', $cufid))->toBeTrue()
        ->and($managerCufid->can('update', $cufid))->toBeTrue()
        ->and($managerCufid->can('view', $cc))->toBeFalse()
        ->and($managerCufid->can('update', $cc))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Consumer — own projects, no management
// ---------------------------------------------------------------------------

it('lets a consumer view only its own project and never manage', function () {
    $konsumen = projUser('konsumen');
    $own = Project::factory()->ownedBy($konsumen)->create();
    $other = Project::factory()->create();

    expect($konsumen->can('view', $own))->toBeTrue()
        ->and($konsumen->can('view', $other))->toBeFalse()
        ->and($konsumen->can('update', $own))->toBeFalse()
        ->and($konsumen->can('delete', $own))->toBeFalse()
        ->and($konsumen->can('create', Project::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Bank Mitra — own financed projects, read-only (§6.5)
// ---------------------------------------------------------------------------

it('lets a Mitra view only its own financed project, read-only', function () {
    $bank = projUser('mitra_pembiayaan');
    $financed = Project::factory()->financedBy($bank)->create();
    $otherBank = projUser('mitra_pembiayaan');
    $otherFinanced = Project::factory()->financedBy($otherBank)->create();

    expect($bank->can('view', $financed))->toBeTrue()
        // never mutate
        ->and($bank->can('update', $financed))->toBeFalse()
        ->and($bank->can('delete', $financed))->toBeFalse()
        ->and($bank->can('create', Project::class))->toBeFalse()
        // another bank's project is closed off
        ->and($bank->can('view', $otherFinanced))->toBeFalse();
});

// ---------------------------------------------------------------------------
// §6.5 — BankMitraScope now enforces own-project at the query level
// ---------------------------------------------------------------------------

it('hides other banks projects from a Mitra at the query level', function () {
    $bank = projUser('mitra_pembiayaan');
    $mine = Project::factory()->financedBy($bank)->create();
    $theirs = Project::factory()->financedBy(projUser('mitra_pembiayaan'))->create();
    Project::factory()->create(); // unfinanced

    $this->actingAs($bank);

    $visible = Project::all();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->is($mine))->toBeTrue();

    // Direct lookup of another bank's project returns nothing for the Mitra.
    expect(Project::find($theirs->id))->toBeNull();
});

it('does not constrain non-Mitra queries', function () {
    Project::factory()->financedBy(projUser('mitra_pembiayaan'))->create();
    Project::factory()->count(2)->create();

    $this->actingAs(projUser('direktur'));

    expect(Project::count())->toBe(3);
});
