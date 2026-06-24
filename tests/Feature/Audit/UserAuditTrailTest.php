<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/** Truncate the audit trail so each test asserts only on its own action. */
function clearAudit(): void
{
    AuditLog::query()->delete();
}

it('records a created entry with a redacted password and no prior state', function () {
    $actor = User::factory()->create();
    clearAudit();

    $this->actingAs($actor);
    $user = User::factory()->create(['name' => 'Budi', 'email' => 'budi@cm.test']);

    $log = AuditLog::sole();

    expect($log->action)->toBe('created')
        ->and($log->entity)->toBe(User::class)
        ->and($log->entity_id)->toBe($user->id)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->before)->toBeNull()
        ->and($log->after['email'])->toBe('budi@cm.test')
        ->and($log->after['name'])->toBe('Budi')
        ->and($log->after['password'])->toBe(User::AUDIT_REDACTED)
        ->and($log->after['remember_token'])->toBe(User::AUDIT_REDACTED);
});

it('records only the changed (dirty) attributes on update', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    clearAudit();

    $user->update(['name' => 'New Name']);

    $log = AuditLog::sole();

    expect($log->action)->toBe('updated')
        ->and($log->before)->toBe(['name' => 'Old Name'])
        ->and($log->after)->toBe(['name' => 'New Name'])
        // unchanged columns must not appear in the diff
        ->and($log->after)->not->toHaveKey('email')
        ->and($log->after)->not->toHaveKey('password');
});

it('redacts a password change instead of leaking the hash', function () {
    $user = User::factory()->create();
    clearAudit();

    $user->update(['password' => 'a-brand-new-secret']);

    $log = AuditLog::sole();

    expect($log->action)->toBe('updated')
        ->and($log->before)->toBe(['password' => User::AUDIT_REDACTED])
        ->and($log->after)->toBe(['password' => User::AUDIT_REDACTED]);

    // The real hash must appear nowhere in the trail.
    $raw = AuditLog::query()->get()->map->toJson()->implode('');
    expect($raw)->not->toContain($user->fresh()->password)
        ->and(Hash::check('a-brand-new-secret', $user->fresh()->password))->toBeTrue();
});

it('does not write a row when only ignored columns change', function () {
    $user = User::factory()->create();
    clearAudit();

    $user->touch(); // bumps updated_at only

    expect(AuditLog::count())->toBe(0);
});

it('records a deleted entry with the prior snapshot', function () {
    $user = User::factory()->create();
    clearAudit();

    $user->delete(); // soft delete

    $log = AuditLog::sole();

    expect($log->action)->toBe('deleted')
        ->and($log->after)->toBeNull()
        ->and($log->before['email'])->toBe($user->email)
        ->and($log->before['password'])->toBe(User::AUDIT_REDACTED);
});

it('records a restored entry', function () {
    $user = User::factory()->create();
    $user->delete();
    clearAudit();

    $user->restore();

    $log = AuditLog::sole();

    expect($log->action)->toBe('restored')
        ->and($log->before)->toBeNull()
        ->and($log->after['email'])->toBe($user->email);
});

it('captures a null actor for unauthenticated (system) mutations', function () {
    clearAudit();

    $user = User::factory()->create();

    expect(AuditLog::sole())
        ->action->toBe('created')
        ->user_id->toBeNull()
        ->entity_id->toBe($user->id);
});

it('never stores the password hash for any action', function () {
    $user = User::factory()->create();
    $user->update(['name' => 'Renamed', 'password' => 'another-secret']);
    $user->delete();

    $hash = $user->fresh()->password;
    $raw = AuditLog::query()->get()->map->toJson()->implode('');

    expect($raw)->not->toContain($hash);
});
