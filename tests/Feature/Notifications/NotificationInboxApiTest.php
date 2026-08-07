<?php

use App\Models\Role;
use App\Models\User;
use App\Notifications\BaseNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/** A concrete notification for seeding the inbox — payload is neutral (7-1). */
class InboxStubNotification extends BaseNotification
{
    public function __construct(private string $label = 'Pembaruan') {}

    public function event(): string
    {
        return 'inbox.stub';
    }

    public function title(): string
    {
        return $this->label;
    }

    public function body(): string
    {
        return 'Ada pembaruan pada proyek Anda.';
    }

    public function entityType(): ?string
    {
        return 'project';
    }

    public function entityId(): int|string|null
    {
        return 7;
    }

    public function actionUrl(): ?string
    {
        return 'https://example.test/projects/7';
    }
}

function niUser(string $role): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
}

/** Give $user $count notifications (all unread). */
function niSeed(User $user, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $user->notify(new InboxStubNotification("Notif {$i}"));
    }
}

// ---------------------------------------------------------------------------
// List — own only, paginated, neutral shape
// ---------------------------------------------------------------------------

it('lists the account own notifications, paginated', function () {
    $me = niUser('konsumen');
    niSeed($me, 3);
    niSeed(niUser('konsumen'), 2); // someone else's — must never appear

    Sanctum::actingAs($me);

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3)          // paginated envelope
        ->assertJsonPath('data.0.event', 'inbox.stub');
});

it('filters to unread with ?unread=1', function () {
    $me = niUser('konsumen');
    niSeed($me, 3);
    $me->notifications()->first()->markAsRead(); // one read → two unread

    Sanctum::actingAs($me);

    $this->getJson('/api/v1/notifications?unread=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('exposes only the neutral payload — never internal columns', function () {
    $me = niUser('mandor');
    niSeed($me, 1);

    Sanctum::actingAs($me);

    $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

    expect(array_keys($row))->toBe([
        'id', 'event', 'title', 'body', 'entity_type', 'entity_id', 'action_url', 'read_at', 'created_at',
    ]);

    // No notifiable pointer, no notification class name leaks.
    $raw = json_encode($row);
    expect($raw)->not->toContain('notifiable')
        ->and($raw)->not->toContain('InboxStubNotification');
});

// ---------------------------------------------------------------------------
// Unread count
// ---------------------------------------------------------------------------

it('reports an accurate unread count', function () {
    $me = niUser('konsumen');
    niSeed($me, 4);
    $me->notifications()->take(1)->get()->markAsRead();

    Sanctum::actingAs($me);

    $this->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 3);
});

// ---------------------------------------------------------------------------
// Mark one / all — idempotent
// ---------------------------------------------------------------------------

it('marks one notification read and is idempotent', function () {
    $me = niUser('konsumen');
    niSeed($me, 2);
    $id = $me->notifications()->first()->id;

    Sanctum::actingAs($me);

    $first = $this->postJson("/api/v1/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('meta.message', 'Notifikasi ditandai dibaca.')
        ->json('data.read_at');

    expect($first)->not->toBeNull()
        ->and($me->unreadNotifications()->count())->toBe(1);

    // Second call is a no-op: still 200, read_at unchanged (not re-stamped).
    $second = $this->postJson("/api/v1/notifications/{$id}/read")
        ->assertOk()->json('data.read_at');
    expect($second)->toBe($first);
});

it('marks all read and is idempotent', function () {
    $me = niUser('konsumen');
    niSeed($me, 3);

    Sanctum::actingAs($me);

    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.marked', 3)
        ->assertJsonPath('meta.message', 'Semua notifikasi ditandai dibaca.');

    expect($me->unreadNotifications()->count())->toBe(0);

    // Nothing left unread → marking again reports 0, not an error.
    $this->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.marked', 0);
});

// ---------------------------------------------------------------------------
// Isolation — another account's notification is invisible AND unmarkable (404)
// ---------------------------------------------------------------------------

it('never lets an account read or mark another account notification', function () {
    $me = niUser('konsumen');
    $other = niUser('konsumen');
    niSeed($other, 1);
    $theirId = $other->notifications()->first()->id;

    Sanctum::actingAs($me);

    // Not in my list, not in my count.
    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);

    // Marking someone else's → 404 (existence never confirmed via a 403).
    $this->postJson("/api/v1/notifications/{$theirId}/read")->assertNotFound();

    // And it stays unread for its real owner.
    expect($other->unreadNotifications()->count())->toBe(1);
});

it('requires authentication for every inbox endpoint', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/some-id/read')->assertUnauthorized();
});
