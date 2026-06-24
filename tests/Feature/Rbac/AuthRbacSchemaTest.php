<?php

use App\Enums\Bidang;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeRole(string $name, int $level): Role
{
    return Role::create(['name' => $name, 'guard_name' => 'web', 'level' => $level]);
}

it('assigns a role and exposes the hierarchy level', function () {
    $role = makeRole('manager', Role::LEVEL_MANAGEMENT);

    $user = User::factory()->create([
        'role_id' => $role->id,
        'bidang' => Bidang::Cufid,
    ]);

    expect($user->role)->toBeInstanceOf(Role::class)
        ->and($user->role->level)->toBe(3)
        ->and($user->level())->toBe(3)
        ->and($role->members)->toHaveCount(1);
});

it('casts is_protected to boolean and bidang to the Bidang enum', function () {
    $user = User::factory()->create([
        'is_protected' => 1,
        'bidang' => 'solit',
    ]);

    expect($user->refresh()->is_protected)->toBeTrue()
        ->and($user->bidang)->toBe(Bidang::Solit);
});

it('tracks the creator hierarchy via created_by', function () {
    $creator = User::factory()->create();
    $child = User::factory()->create(['created_by' => $creator->id]);

    expect($child->creator->is($creator))->toBeTrue()
        ->and($creator->createdUsers->pluck('id'))->toContain($child->id);
});

it('soft deletes users', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull()
        ->and($user->fresh()->deleted_at)->not->toBeNull();
});

it('stores audit log before/after as arrays', function () {
    $actor = User::factory()->create();

    $log = AuditLog::create([
        'user_id' => $actor->id,
        'action' => 'updated',
        'entity' => User::class,
        'entity_id' => $actor->id,
        'before' => ['name' => 'Old'],
        'after' => ['name' => 'New'],
        'ip' => '127.0.0.1',
    ]);

    $fresh = $log->fresh();

    expect($fresh->before)->toBe(['name' => 'Old'])
        ->and($fresh->after)->toBe(['name' => 'New'])
        ->and($fresh->user->is($actor))->toBeTrue();
});
