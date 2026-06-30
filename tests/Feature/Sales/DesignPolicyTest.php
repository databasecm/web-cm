<?php

use App\Enums\Bidang;
use App\Models\Design;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function dUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// Manage — staff who manage the parent project (bidang scoped)
// ---------------------------------------------------------------------------

it('lets project-managing staff manage designs, scoped by bidang', function () {
    $managerCufid = dUser('manager', Bidang::Cufid);
    $cufidDesign = Design::factory()->for(Project::factory()->inBidang(Bidang::Cufid))->create();
    $ccDesign = Design::factory()->for(Project::factory()->inBidang(Bidang::Cc))->create();

    expect($managerCufid->can('create', Design::class))->toBeTrue()
        ->and($managerCufid->can('update', $cufidDesign))->toBeTrue()
        ->and($managerCufid->can('submit', $cufidDesign))->toBeTrue()
        // cross-bidang → denied
        ->and($managerCufid->can('update', $ccDesign))->toBeFalse()
        ->and($managerCufid->can('submit', $ccDesign))->toBeFalse();

    foreach (['owner', 'direktur'] as $name) {
        expect(dUser($name)->can('update', $ccDesign))->toBeTrue("{$name} manages any design");
    }
});

// ---------------------------------------------------------------------------
// View — owning consumer & financing bank; not other consumers
// ---------------------------------------------------------------------------

it('lets the owning consumer view their design but not others', function () {
    $project = Project::factory()->create();
    $konsumen = User::find($project->konsumen_id);
    $design = Design::factory()->for($project)->create();
    $otherKonsumen = dUser('konsumen');

    expect($konsumen->can('view', $design))->toBeTrue()
        ->and($otherKonsumen->can('view', $design))->toBeFalse()
        // a consumer never manages designs
        ->and($konsumen->can('update', $design))->toBeFalse()
        ->and($konsumen->can('submit', $design))->toBeFalse();
});

it('lets the financing Mitra view a design read-only, but not other banks', function () {
    $bank = dUser('mitra_pembiayaan');
    $design = Design::factory()->for(Project::factory()->financedBy($bank))->create();
    $otherBank = dUser('mitra_pembiayaan');

    expect($bank->can('view', $design))->toBeTrue()
        ->and($bank->can('update', $design))->toBeFalse()
        ->and($otherBank->can('view', $design))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Approve — only the owning consumer, only a submitted version
// ---------------------------------------------------------------------------

it('lets only the owning consumer approve, and only a submitted version', function () {
    $project = Project::factory()->create();
    $konsumen = User::find($project->konsumen_id);

    $draft = Design::factory()->for($project)->create();
    $submitted = Design::factory()->for($project)->version(2)->submitted()->create();

    expect($konsumen->can('approve', $submitted))->toBeTrue()
        // not a submitted version → cannot approve
        ->and($konsumen->can('approve', $draft))->toBeFalse();

    // staff and other consumers cannot approve
    expect(dUser('manager', Bidang::Cufid)->can('approve', $submitted))->toBeFalse()
        ->and(dUser('konsumen')->can('approve', $submitted))->toBeFalse()
        ->and(dUser('owner')->can('approve', $submitted))->toBeFalse();
});
