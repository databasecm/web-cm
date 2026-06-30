<?php

use App\Enums\Bidang;
use App\Enums\RabStatus;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function rabUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

it('scopes RAB management to staff who manage the parent project (bidang)', function () {
    $managerCufid = rabUser('manager', Bidang::Cufid);
    $cufidRab = Rab::factory()->for(Project::factory()->inBidang(Bidang::Cufid))->create();
    $ccRab = Rab::factory()->for(Project::factory()->inBidang(Bidang::Cc))->create();

    expect($managerCufid->can('create', Rab::class))->toBeTrue()
        ->and($managerCufid->can('update', $cufidRab))->toBeTrue()
        ->and($managerCufid->can('submit', $cufidRab))->toBeTrue()
        ->and($managerCufid->can('update', $ccRab))->toBeFalse();

    expect(rabUser('direktur')->can('update', $ccRab))->toBeTrue();
});

it('lets the owning consumer view and approve, but not other consumers or staff', function () {
    $project = Project::factory()->create();
    $konsumen = User::find($project->konsumen_id);
    $rab = Rab::factory()->for($project)->status(RabStatus::Submitted)->create();

    expect($konsumen->can('view', $rab))->toBeTrue()
        ->and($konsumen->can('approve', $rab))->toBeTrue()
        ->and($konsumen->can('update', $rab))->toBeFalse()
        ->and(rabUser('konsumen')->can('view', $rab))->toBeFalse()
        ->and(rabUser('manager', Bidang::Cufid)->can('approve', $rab))->toBeFalse();
});

it('lets the financing Mitra view a RAB read-only, but not other banks', function () {
    $bank = rabUser('mitra_pembiayaan');
    $rab = Rab::factory()->for(Project::factory()->financedBy($bank))->create();
    $otherBank = rabUser('mitra_pembiayaan');

    expect($bank->can('view', $rab))->toBeTrue()
        ->and($bank->can('update', $rab))->toBeFalse()
        ->and($otherBank->can('view', $rab))->toBeFalse();
});
