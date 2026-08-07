<?php

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\RecipientResolver;
use App\Policies\NotificationPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/** A concrete stand-in for the abstract base — the only mechanism under test in 7-1. */
class FoundationStubNotification extends BaseNotification
{
    public function event(): string
    {
        return 'foundation.stub';
    }

    public function title(): string
    {
        return 'Contoh notifikasi';
    }

    public function body(): string
    {
        return 'Ada pembaruan pada proyek Anda.'; // neutral — no amount, no detail
    }

    public function entityType(): ?string
    {
        return 'project';
    }

    public function entityId(): int|string|null
    {
        return 42;
    }

    public function actionUrl(): ?string
    {
        return 'https://example.test/projects/42';
    }
}

function nfUser(string $role): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
}

// ---------------------------------------------------------------------------
// The base persists exactly the neutral payload, via the database channel
// ---------------------------------------------------------------------------

it('persists a database notification with the fixed neutral payload', function () {
    $user = nfUser('finance');

    $user->notify(new FoundationStubNotification); // QUEUE=sync → runs + persists inline

    $row = $user->notifications()->sole();
    expect($row)->toBeInstanceOf(DatabaseNotification::class)
        ->and($row->type)->toBe(FoundationStubNotification::class)
        // exactly the six standard keys — nothing more may ride along
        ->and(array_keys($row->data))->toBe([
            'event', 'title', 'body', 'entity_type', 'entity_id', 'action_url',
        ])
        ->and($row->data['event'])->toBe('foundation.stub')
        ->and($row->data['entity_type'])->toBe('project')
        ->and($row->data['entity_id'])->toBe(42)
        ->and($row->read_at)->toBeNull();
});

it('never lets a money amount ride in the body', function () {
    // The body of every notification kind must read as a neutral knock. Guard
    // the invariant at the mechanism level: no digits-as-currency in the body.
    $body = (new FoundationStubNotification)->payload()['body'];

    expect($body)->not->toMatch('/\d/'); // no amounts, no counts — detail is behind the gate
});

// ---------------------------------------------------------------------------
// Channels are read from config — adding one is a config change, not a code one
// ---------------------------------------------------------------------------

it('reads its delivery channels from config (A3-ready)', function () {
    $notification = new FoundationStubNotification;
    $user = nfUser('owner');

    // It is queued so triggers never block on delivery.
    expect($notification)->toBeInstanceOf(ShouldQueue::class);

    // Default ships with the in-app database channel only.
    config(['notifications.channels' => ['database']]);
    expect($notification->via($user))->toBe(['database']);

    // Appending 'mail' at go-live flows straight through via() — no trigger,
    // no base change. (We assert the wiring; nothing is actually sent here.)
    config(['notifications.channels' => ['database', 'mail']]);
    expect($notification->via($user))->toBe(['database', 'mail']);
});

// ---------------------------------------------------------------------------
// Only login accounts are notifiable — data-only entities (workers) are not
// ---------------------------------------------------------------------------

it('makes only User accounts notifiable, never Employee', function () {
    expect(in_array(Notifiable::class, class_uses_recursive(User::class), true))->toBeTrue()
        ->and(method_exists(User::class, 'notify'))->toBeTrue()
        // Karyawan/tukang are data, not accounts (§7) — they can never receive.
        ->and(in_array(Notifiable::class, class_uses_recursive(Employee::class), true))->toBeFalse()
        ->and(method_exists(Employee::class, 'notify'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Read policy — a notification is visible only to the account it addresses
// ---------------------------------------------------------------------------

it('lets only the addressed account read and mark its notification', function () {
    $owner = nfUser('finance');
    $stranger = nfUser('hr');

    $owner->notify(new FoundationStubNotification);
    $notification = $owner->notifications()->sole();

    $policy = new NotificationPolicy;
    expect($policy->view($owner, $notification))->toBeTrue()
        ->and($policy->update($owner, $notification))->toBeTrue()
        ->and($policy->view($stranger, $notification))->toBeFalse()
        ->and($policy->update($stranger, $notification))->toBeFalse();

    // Same result through the Gate (policy is registered).
    expect($owner->can('view', $notification))->toBeTrue()
        ->and($stranger->can('view', $notification))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Recipient resolver — fail-closed skeleton with policy-backed primitives
// ---------------------------------------------------------------------------

it('fails closed for events without a rule and exposes the overseer set', function () {
    $resolver = new RecipientResolver;
    $owner = nfUser('owner');
    $direktur = nfUser('direktur');
    nfUser('finance'); // not an overseer

    // No rule registered yet → nobody is notified (silence never leaks).
    $entity = new User;
    expect($resolver->recipientsFor('anything.unknown', $entity))->toBeEmpty();

    // Overseers = Owner + Direktur, resolved from the role hierarchy (§6.3).
    $overseers = $resolver->overseers();
    expect($overseers->pluck('id')->sort()->values()->all())
        ->toBe(collect([$owner->id, $direktur->id])->sort()->values()->all());
});
