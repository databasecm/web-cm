<?php

use App\Enums\Bidang;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| RBAC Specification (living documentation)
|--------------------------------------------------------------------------
|
| The consolidated Definition-of-Done gate for Phase 1 access control. Every
| dataset row below is a clause of the spec in CLAUDE.md §6: actor role × action
| × scope, with the expected outcome made explicit. Every forbidden cell asserts
| denial (which surfaces to the HTTP layer as 403).
|
| Detailed, per-concern coverage also lives in UserPolicyTest, UserAccountRequest
| Test, BankMitraScopeTest, UserAuditTrailTest and UserResourceTest; this file is
| the single-glance matrix.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/** Create a user holding the named role, optional bidang and protection flag. */
function specUser(string $roleName, ?Bidang $bidang = null, bool $protected = false): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
        'is_protected' => $protected,
    ]);
}

// ===========================================================================
// §6.3 — Account-management capability is strictly per level
//   Only Owner (1), Direktur (2) and Manager (3) may manage accounts at all.
//   Finance/HR (3) handle data not accounts; Mitra (4), Mandor (5),
//   Konsumen (6) carry no capability.
// ===========================================================================

it('grants account-management capability strictly per level', function (
    string $role,
    ?Bidang $bidang,
    bool $viewAny,
    bool $create,
) {
    $actor = specUser($role, $bidang);
    $this->actingAs($actor);

    expect($actor->can('viewAny', User::class))->toBe($viewAny)
        ->and($actor->can('create', User::class))->toBe($create);
})->with([
    'Owner (L1) — manages accounts' => ['owner', null, true, true],
    'Direktur (L2) — manages accounts' => ['direktur', null, true, true],
    'Manager (L3) — manages accounts' => ['manager', Bidang::Cufid, true, true],
    'Finance (L3) — data, not accounts' => ['finance', null, false, false],
    'HR (L3) — data, not accounts' => ['hr', null, false, false],
    'Mitra Pembiayaan (L4) — none' => ['mitra_pembiayaan', null, false, false],
    'Supplier (L4) — none' => ['supplier', null, false, false],
    'Mandor (L5) — no subordinates' => ['mandor', Bidang::Cufid, false, false],
    'Konsumen (L6) — none' => ['konsumen', null, false, false],
]);

// ===========================================================================
// §6.3 / §6.4 — Reachability: an actor may view/update/delete a target only
//   when it outranks the target AND (if bidang-scoped) shares its bidang.
//   Note: a bidang-scoped Manager cannot reach bidang-less accounts
//   (Konsumen/Mitra/Supplier) — they fall outside any business unit.
// ===========================================================================

it('enforces hierarchy and bidang reachability for view/update/delete', function (
    string $actorRole,
    ?Bidang $actorBidang,
    string $targetRole,
    ?Bidang $targetBidang,
    bool $canManage,
) {
    $actor = specUser($actorRole, $actorBidang);
    $target = specUser($targetRole, $targetBidang);
    $this->actingAs($actor);

    expect($actor->can('view', $target))->toBe($canManage)
        ->and($actor->can('update', $target))->toBe($canManage)
        ->and($actor->can('delete', $target))->toBe($canManage);
})->with([
    'Owner → Direktur (down)' => ['owner', null, 'direktur', null, true],
    'Owner → Konsumen (down)' => ['owner', null, 'konsumen', null, true],
    'Direktur → Owner (up) — blocked' => ['direktur', null, 'owner', null, false],
    'Direktur → Direktur (peer) — blocked' => ['direktur', null, 'direktur', null, false],
    'Direktur → Manager (down)' => ['direktur', null, 'manager', Bidang::Cufid, true],
    'Manager → Mandor, same bidang (down)' => ['manager', Bidang::Cufid, 'mandor', Bidang::Cufid, true],
    'Manager → Mandor, other bidang — blocked' => ['manager', Bidang::Cufid, 'mandor', Bidang::Cc, false],
    'Manager → Manager (peer) — blocked' => ['manager', Bidang::Cufid, 'manager', Bidang::Cufid, false],
    'Manager → Konsumen (no bidang) — blocked' => ['manager', Bidang::Cufid, 'konsumen', null, false],
    'Manager → Supplier (no bidang) — blocked' => ['manager', Bidang::Cufid, 'supplier', null, false],
    'Finance → Mandor — blocked (no capability)' => ['finance', null, 'mandor', Bidang::Cufid, false],
    'Mitra → Konsumen — blocked (no capability)' => ['mitra_pembiayaan', null, 'konsumen', null, false],
    'Mandor → Konsumen — blocked (no subordinates)' => ['mandor', Bidang::Cufid, 'konsumen', null, false],
]);

// ===========================================================================
// §6 — Every account may always view itself, but never delete itself (§6.2).
// ===========================================================================

it('lets every account view itself', function (string $role, ?Bidang $bidang) {
    $self = specUser($role, $bidang);
    expect($self->can('view', $self))->toBeTrue();
})->with([
    'Owner' => ['owner', null],
    'Direktur' => ['direktur', null],
    'Manager' => ['manager', Bidang::Cufid],
    'Finance' => ['finance', null],
    'Mitra' => ['mitra_pembiayaan', null],
    'Mandor' => ['mandor', Bidang::Cufid],
    'Konsumen' => ['konsumen', null],
]);

it('never lets an account delete itself', function (string $role, ?Bidang $bidang) {
    $self = specUser($role, $bidang);
    $this->actingAs($self);
    expect($self->can('delete', $self))->toBeFalse();
})->with([
    'Owner' => ['owner', null],
    'Direktur' => ['direktur', null],
    'Manager' => ['manager', Bidang::Cufid],
    'Finance' => ['finance', null],
    'Konsumen' => ['konsumen', null],
]);

// ===========================================================================
// §6.1 — A protected Owner can never be deleted, by anyone.
// ===========================================================================

it('never lets a protected Owner be deleted', function (string $role, ?Bidang $bidang) {
    $owner = specUser('owner', protected: true);
    $challenger = specUser($role, $bidang);
    $this->actingAs($challenger);

    expect($challenger->can('delete', $owner))->toBeFalse();
})->with([
    'by another Owner' => ['owner', null],
    'by Direktur' => ['direktur', null],
    'by Manager' => ['manager', Bidang::Cufid],
    'by Finance' => ['finance', null],
    'by Mitra' => ['mitra_pembiayaan', null],
]);

// ===========================================================================
// §6.3 / §6.4 — Role assignment: only strictly below the actor, and (when
//   bidang-scoped) only within the actor's own bidang.
// ===========================================================================

it('authorizes role assignment only strictly below the actor and within bidang', function (
    string $actorRole,
    ?Bidang $actorBidang,
    string $assignRole,
    ?string $assignBidang,
    bool $allowed,
) {
    $actor = specUser($actorRole, $actorBidang);
    $this->actingAs($actor);
    $role = Role::where('name', $assignRole)->first();

    expect($actor->can('assign-account', [$role, $assignBidang]))->toBe($allowed);
})->with([
    'Owner may assign Direktur' => ['owner', null, 'direktur', null, true],
    'Owner may not assign Owner (peer)' => ['owner', null, 'owner', null, false],
    'Direktur may assign Manager' => ['direktur', null, 'manager', Bidang::Cufid->value, true],
    'Manager may assign Mandor in own bidang' => ['manager', Bidang::Cufid, 'mandor', Bidang::Cufid->value, true],
    'Manager may not assign Mandor cross-bidang' => ['manager', Bidang::Cufid, 'mandor', Bidang::Cc->value, false],
    'Manager may not assign upward' => ['manager', Bidang::Cufid, 'direktur', null, false],
    'Finance may not assign (no capability)' => ['finance', null, 'konsumen', null, false],
    'Mitra may not assign (no capability)' => ['mitra_pembiayaan', null, 'konsumen', null, false],
]);
