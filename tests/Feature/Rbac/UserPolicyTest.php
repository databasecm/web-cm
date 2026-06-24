<?php

use App\Enums\Bidang;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * Create a user holding the named role, optional bidang and protection flag.
 */
function actor(string $roleName, ?Bidang $bidang = null, bool $protected = false): User
{
    $role = Role::where('name', $roleName)->firstOrFail();

    return User::factory()->create([
        'role_id' => $role->id,
        'bidang' => $bidang,
        'is_protected' => $protected,
    ]);
}

// ---------------------------------------------------------------------------
// Hard rule §6.1 / §6.2 — Owner protection and the self-action ban
// ---------------------------------------------------------------------------

it('forbids deleting a protected Owner — no matter who asks', function () {
    $owner = actor('owner', protected: true);

    foreach (['direktur', 'manager', 'finance', 'hr'] as $roleName) {
        $challenger = actor($roleName, $roleName === 'manager' ? Bidang::Cufid : null);
        expect($challenger->can('delete', $owner))->toBeFalse();
    }

    // Even another (hypothetical, unprotected) owner cannot delete a protected one.
    expect(actor('owner')->can('delete', $owner))->toBeFalse();
});

it('forbids any account from deleting itself', function () {
    foreach (['owner', 'direktur', 'finance', 'hr'] as $roleName) {
        $self = actor($roleName);
        expect($self->can('delete', $self))->toBeFalse();
    }

    $manager = actor('manager', Bidang::Cufid);
    expect($manager->can('delete', $manager))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Hard rule §6.3 — account management by level
// ---------------------------------------------------------------------------

it('lets Owner and Direktur manage everyone below them', function () {
    $owner = actor('owner');
    $direktur = actor('direktur');
    $manager = actor('manager', Bidang::Cufid);
    $mandor = actor('mandor', Bidang::Solit);

    expect($owner->can('delete', $direktur))->toBeTrue()
        ->and($owner->can('update', $manager))->toBeTrue()
        ->and($owner->can('delete', $mandor))->toBeTrue()
        ->and($direktur->can('delete', $manager))->toBeTrue()
        ->and($direktur->can('update', $mandor))->toBeTrue();
});

it('forbids managing accounts at or above your own level', function () {
    $direktur = actor('direktur');
    $owner = actor('owner');
    $manager = actor('manager', Bidang::Cufid);
    $peerManager = actor('manager', Bidang::Cufid);

    // upward
    expect($direktur->can('update', $owner))->toBeFalse()
        ->and($manager->can('delete', $direktur))->toBeFalse()
        // sideways (same level)
        ->and($manager->can('update', $peerManager))->toBeFalse();
});

it('gives Mitra (L4) zero account-management rights', function () {
    foreach (['mitra_pembiayaan', 'supplier'] as $roleName) {
        $mitra = actor($roleName);
        $konsumen = actor('konsumen');

        expect($mitra->can('viewAny', User::class))->toBeFalse()
            ->and($mitra->can('create', User::class))->toBeFalse()
            ->and($mitra->can('update', $konsumen))->toBeFalse()
            ->and($mitra->can('delete', $konsumen))->toBeFalse()
            ->and($mitra->can('view', $konsumen))->toBeFalse();
    }
});

it('gives Mandor (L5) and Konsumen (L6) no subordinate accounts', function () {
    $mandor = actor('mandor', Bidang::Cufid);
    $konsumen = actor('konsumen');
    $otherKonsumen = actor('konsumen');

    expect($mandor->can('create', User::class))->toBeFalse()
        ->and($mandor->can('delete', $konsumen))->toBeFalse()
        ->and($mandor->can('viewAny', User::class))->toBeFalse()
        ->and($konsumen->can('delete', $otherKonsumen))->toBeFalse()
        ->and($konsumen->can('viewAny', User::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Hard rule §6.4 — Manager bidang scoping
// ---------------------------------------------------------------------------

it('scopes a Manager to accounts within its own bidang', function () {
    $managerCufid = actor('manager', Bidang::Cufid);
    $mandorCufid = actor('mandor', Bidang::Cufid);
    $mandorCc = actor('mandor', Bidang::Cc);

    // same bidang, below → allowed
    expect($managerCufid->can('update', $mandorCufid))->toBeTrue()
        ->and($managerCufid->can('delete', $mandorCufid))->toBeTrue()
        ->and($managerCufid->can('view', $mandorCufid))->toBeTrue()
        // cross bidang → forbidden
        ->and($managerCufid->can('update', $mandorCc))->toBeFalse()
        ->and($managerCufid->can('delete', $mandorCc))->toBeFalse()
        ->and($managerCufid->can('view', $mandorCc))->toBeFalse();
});

it('treats company-wide L3 (Finance/HR, no bidang) as unscoped by bidang', function () {
    $finance = actor('finance');
    $mandorCufid = actor('mandor', Bidang::Cufid);
    $mandorCc = actor('mandor', Bidang::Cc);

    expect($finance->can('delete', $mandorCufid))->toBeTrue()
        ->and($finance->can('delete', $mandorCc))->toBeTrue();
});

// ---------------------------------------------------------------------------
// view / viewAny / create capability matrix
// ---------------------------------------------------------------------------

it('allows the account list only to management-capable levels', function () {
    expect(actor('owner')->can('viewAny', User::class))->toBeTrue()
        ->and(actor('direktur')->can('viewAny', User::class))->toBeTrue()
        ->and(actor('manager', Bidang::Cufid)->can('viewAny', User::class))->toBeTrue()
        ->and(actor('finance')->can('viewAny', User::class))->toBeTrue()
        ->and(actor('hr')->can('viewAny', User::class))->toBeTrue()
        ->and(actor('mitra_pembiayaan')->can('viewAny', User::class))->toBeFalse()
        ->and(actor('mandor', Bidang::Cufid)->can('viewAny', User::class))->toBeFalse()
        ->and(actor('konsumen')->can('viewAny', User::class))->toBeFalse();
});

it('lets any account view itself', function () {
    foreach (['owner', 'mitra_pembiayaan', 'mandor', 'konsumen'] as $roleName) {
        $self = actor($roleName, $roleName === 'mandor' ? Bidang::Cufid : null);
        expect($self->can('view', $self))->toBeTrue();
    }
});

it('forbids assigning a role that is not strictly below the actor', function () {
    $owner = actor('owner');
    $direktur = actor('direktur');
    $managerCufid = actor('manager', Bidang::Cufid);

    $ownerRole = Role::where('name', 'owner')->first();
    $direkturRole = Role::where('name', 'direktur')->first();
    $managerRole = Role::where('name', 'manager')->first();
    $mandorRole = Role::where('name', 'mandor')->first();

    expect($owner->can('assign-account', [$direkturRole, null]))->toBeTrue()
        ->and($owner->can('assign-account', [$ownerRole, null]))->toBeFalse()
        ->and($direktur->can('assign-account', [$managerRole, Bidang::Cufid->value]))->toBeTrue()
        // manager assigning a mandor in its own bidang → ok
        ->and($managerCufid->can('assign-account', [$mandorRole, Bidang::Cufid->value]))->toBeTrue()
        // manager assigning a mandor in another bidang → forbidden
        ->and($managerCufid->can('assign-account', [$mandorRole, Bidang::Cc->value]))->toBeFalse()
        // manager assigning a role above itself → forbidden
        ->and($managerCufid->can('assign-account', [$direkturRole, null]))->toBeFalse();
});

it('denies the assign gate to non-management actors', function () {
    $mitra = actor('mitra_pembiayaan');
    $mandorRole = Role::where('name', 'mandor')->first();

    expect($mitra->can('assign-account', [$mandorRole, Bidang::Cufid->value]))->toBeFalse();
});
