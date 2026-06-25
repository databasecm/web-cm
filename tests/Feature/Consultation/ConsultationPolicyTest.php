<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Create a user holding the named role and optional bidang.
 */
function user(string $roleName, ?Bidang $bidang = null): User
{
    $role = Role::where('name', $roleName)->firstOrFail();

    return User::factory()->create([
        'role_id' => $role->id,
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// view / viewAny capability
// ---------------------------------------------------------------------------

it('lets triage staff and consumers browse the consultation list', function () {
    expect(user('owner')->can('viewAny', Consultation::class))->toBeTrue()
        ->and(user('direktur')->can('viewAny', Consultation::class))->toBeTrue()
        ->and(user('manager', Bidang::Cufid)->can('viewAny', Consultation::class))->toBeTrue()
        ->and(user('konsumen')->can('viewAny', Consultation::class))->toBeTrue()
        // Not part of consultations:
        ->and(user('finance')->can('viewAny', Consultation::class))->toBeFalse()
        ->and(user('hr')->can('viewAny', Consultation::class))->toBeFalse()
        ->and(user('mitra_pembiayaan')->can('viewAny', Consultation::class))->toBeFalse()
        ->and(user('mandor', Bidang::Cufid)->can('viewAny', Consultation::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Consumer ownership — sees / responds to own thread only
// ---------------------------------------------------------------------------

it('lets a consumer view and respond to their own thread only', function () {
    $owner = user('konsumen');
    $other = user('konsumen');
    $thread = Consultation::factory()->ownedBy($owner)->inBidang(Bidang::Cufid)->create();

    expect($owner->can('view', $thread))->toBeTrue()
        ->and($owner->can('respond', $thread))->toBeTrue()
        // a consumer cannot edit the thread record (claim/status) themselves
        ->and($owner->can('update', $thread))->toBeFalse()
        // someone else's thread is invisible
        ->and($other->can('view', $thread))->toBeFalse()
        ->and($other->can('respond', $thread))->toBeFalse();
});

// ---------------------------------------------------------------------------
// §6.4 — Manager is confined to its own bidang
// ---------------------------------------------------------------------------

it('scopes a Manager to consultations in its own bidang', function () {
    $managerCufid = user('manager', Bidang::Cufid);
    $cufidThread = Consultation::factory()->inBidang(Bidang::Cufid)->create();
    $ccThread = Consultation::factory()->inBidang(Bidang::Cc)->create();

    expect($managerCufid->can('view', $cufidThread))->toBeTrue()
        ->and($managerCufid->can('update', $cufidThread))->toBeTrue()
        ->and($managerCufid->can('respond', $cufidThread))->toBeTrue()
        // cross-bidang → fully closed
        ->and($managerCufid->can('view', $ccThread))->toBeFalse()
        ->and($managerCufid->can('update', $ccThread))->toBeFalse()
        ->and($managerCufid->can('respond', $ccThread))->toBeFalse();
});

it('lets Owner and Direktur reach every bidang', function () {
    $thread = Consultation::factory()->inBidang(Bidang::BiruGis)->create();

    foreach (['owner', 'direktur'] as $roleName) {
        $actor = user($roleName);
        expect($actor->can('view', $thread))->toBeTrue()
            ->and($actor->can('update', $thread))->toBeTrue()
            ->and($actor->can('respond', $thread))->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// Non-participants are fully excluded
// ---------------------------------------------------------------------------

it('excludes Finance, HR, Mitra and Mandor from consultations entirely', function () {
    $thread = Consultation::factory()->inBidang(Bidang::Cufid)->create();

    foreach (['finance', 'hr', 'mitra_pembiayaan', 'supplier'] as $roleName) {
        $actor = user($roleName);
        expect($actor->can('view', $thread))->toBeFalse()
            ->and($actor->can('update', $thread))->toBeFalse()
            ->and($actor->can('respond', $thread))->toBeFalse();
    }

    // A Mandor in the same bidang is still not a consultation participant.
    $mandor = user('mandor', Bidang::Cufid);
    expect($mandor->can('view', $thread))->toBeFalse()
        ->and($mandor->can('respond', $thread))->toBeFalse();
});

// ---------------------------------------------------------------------------
// respond — closed threads are read-only
// ---------------------------------------------------------------------------

it('forbids responding to a closed thread, even for the owner and staff', function () {
    $konsumen = user('konsumen');
    $manager = user('manager', Bidang::Cufid);
    $closed = Consultation::factory()
        ->ownedBy($konsumen)
        ->inBidang(Bidang::Cufid)
        ->status(ConsultationStatus::Closed)
        ->create();

    expect($konsumen->can('respond', $closed))->toBeFalse()
        ->and($manager->can('respond', $closed))->toBeFalse()
        // viewing the (read-only) history is still allowed
        ->and($konsumen->can('view', $closed))->toBeTrue()
        ->and($manager->can('view', $closed))->toBeTrue();
});

// ---------------------------------------------------------------------------
// create / delete
// ---------------------------------------------------------------------------

it('lets consumers and triage staff create, but only Owner/Direktur delete', function () {
    $thread = Consultation::factory()->inBidang(Bidang::Cufid)->create();

    expect(user('konsumen')->can('create', Consultation::class))->toBeTrue()
        ->and(user('manager', Bidang::Cufid)->can('create', Consultation::class))->toBeTrue()
        ->and(user('owner')->can('create', Consultation::class))->toBeTrue()
        ->and(user('mandor', Bidang::Cufid)->can('create', Consultation::class))->toBeFalse();

    expect(user('owner')->can('delete', $thread))->toBeTrue()
        ->and(user('direktur')->can('delete', $thread))->toBeTrue()
        // even the covering Manager cannot delete a thread
        ->and(user('manager', Bidang::Cufid)->can('delete', $thread))->toBeFalse()
        ->and(user('konsumen')->can('delete', $thread))->toBeFalse();
});
