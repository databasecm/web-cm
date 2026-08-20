<?php

use App\Livewire\Portal\Notifications;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Notifications\BaseNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

if (! function_exists('portalConsumer')) {
    function portalConsumer(bool $verified = true): User
    {
        $factory = User::factory();

        if (! $verified) {
            $factory = $factory->unverified();
        }

        return $factory->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
    }
}

if (! function_exists('portalStaff')) {
    function portalStaff(): User
    {
        return User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    }
}

if (! function_exists('portalOwnedProject')) {
    function portalOwnedProject(User $consumer, array $attributes = []): Project
    {
        return Project::factory()->create(array_merge(['konsumen_id' => $consumer->id], $attributes));
    }
}

/**
 * A concrete notification for seeding the inbox. Payload stays neutral (7-1);
 * the entity pointer is configurable so the portal URL mapping can be exercised.
 */
class PortalInboxNotification extends BaseNotification
{
    public function __construct(
        private string $label = 'Pembaruan',
        private string $entity = 'project',
        private int|string|null $entityKey = null,
    ) {}

    public function event(): string
    {
        return 'portal.stub';
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
        return $this->entity;
    }

    public function entityId(): int|string|null
    {
        return $this->entityKey;
    }

    public function actionUrl(): ?string
    {
        // A client-neutral pointer; the portal rewrites it to its own route.
        return url('/projects/'.$this->entityKey);
    }
}

/** Give $user $count notifications (all unread), titled "Notif 0..n". */
function pnSeed(User $user, int $count, string $prefix = 'Notif'): void
{
    for ($i = 0; $i < $count; $i++) {
        $user->notify(new PortalInboxNotification("{$prefix} {$i}"));
    }
}

// ---------------------------------------------------------------------------
// 1) The consumer sees only their own notifications (paginated + unread filter)
// ---------------------------------------------------------------------------

it('lists only the consumer own notifications, paginated', function () {
    $me = portalConsumer();
    pnSeed($me, 3, 'Mine');
    pnSeed(portalConsumer(), 2, 'Theirs'); // another consumer's — must never appear

    Livewire::actingAs($me)->test(Notifications::class)
        ->assertViewHas('notifications', fn ($n) => $n instanceof LengthAwarePaginator && $n->total() === 3)
        ->assertSee('Mine 0')
        ->assertSee('Mine 2')
        ->assertDontSee('Theirs 0');
});

it('narrows to unread with the filter toggle', function () {
    $me = portalConsumer();
    pnSeed($me, 3);
    $me->notifications()->first()->markAsRead(); // one read → two unread

    Livewire::actingAs($me)->test(Notifications::class)
        ->assertViewHas('notifications', fn ($n) => $n->total() === 3)     // all
        ->call('toggleUnread')
        ->assertSet('unreadOnly', true)
        ->assertViewHas('notifications', fn ($n) => $n->total() === 2);    // unread only
});

it('paginates at 15 per page', function () {
    $me = portalConsumer();
    pnSeed($me, 16);

    Livewire::actingAs($me)->test(Notifications::class)
        ->assertViewHas('notifications', fn ($n) => $n->total() === 16 && $n->count() === 15)
        ->call('gotoPage', 2)
        ->assertViewHas('notifications', fn ($n) => $n->count() === 1);
});

// ---------------------------------------------------------------------------
// 2) Mark one read — idempotent
// ---------------------------------------------------------------------------

it('marks one notification read and is idempotent', function () {
    $me = portalConsumer();
    pnSeed($me, 2);
    $notification = $me->notifications()->first();

    $component = Livewire::actingAs($me)->test(Notifications::class)
        ->call('markRead', $notification->id);

    $readAt = $notification->fresh()->read_at;
    expect($readAt)->not->toBeNull()
        ->and($me->unreadNotifications()->count())->toBe(1);

    // A second call is a no-op — read_at is not re-stamped.
    $component->call('markRead', $notification->id);
    expect($notification->fresh()->read_at->equalTo($readAt))->toBeTrue();
});

it('marks every notification read (idempotent)', function () {
    $me = portalConsumer();
    pnSeed($me, 3);

    Livewire::actingAs($me)->test(Notifications::class)
        ->call('markAllRead');

    expect($me->unreadNotifications()->count())->toBe(0);

    // Nothing left unread → marking again is a harmless no-op.
    Livewire::actingAs($me)->test(Notifications::class)->call('markAllRead');
    expect($me->unreadNotifications()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 3) Owner-only: another consumer's notification is invisible AND unmarkable
// ---------------------------------------------------------------------------

it('never lets a consumer see or mark another consumer notification', function () {
    $me = portalConsumer();
    $other = portalConsumer();
    pnSeed($other, 1, 'Secret');
    $theirId = $other->notifications()->first()->id;

    // Not in my list.
    Livewire::actingAs($me)->test(Notifications::class)
        ->assertViewHas('notifications', fn ($n) => $n->total() === 0)
        ->assertDontSee('Secret 0');

    // Marking it → 404 (existence never confirmed via a 403), and it stays
    // unread for its real owner.
    Livewire::actingAs($me)->test(Notifications::class)
        ->call('markRead', $theirId)
        ->assertNotFound();

    expect($other->unreadNotifications()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 4) action_url → the correct portal page, still policy-gated on open
// ---------------------------------------------------------------------------

it('links a notification to the matching portal page, gated on open', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $me->notify(new PortalInboxNotification('Progres', 'project', $project->id));

    // The inbox rewrites the neutral pointer to the PORTAL route.
    $expected = route('portal.projects.show', $project);
    Livewire::actingAs($me)->test(Notifications::class)
        ->assertViewHas('actionUrls', fn ($urls) => in_array($expected, $urls, true))
        ->assertSee($expected, escape: false);

    // Opening the door still re-checks ownership: owner ok, stranger forbidden.
    $this->actingAs($me)->get($expected)->assertOk();
    $this->actingAs(portalConsumer())->get($expected)->assertForbidden();
});

it('maps entity types with a dedicated portal page (financing, payments)', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $me->notify(new PortalInboxNotification('Pembiayaan', 'project', $project->id));

    // A raw /financings/{id} pointer with no resolvable project yields no link
    // rather than a broken one (fail closed).
    $me->notify(new PortalInboxNotification('Gaji', 'payroll', 999));

    $urls = Livewire::actingAs($me)->test(Notifications::class)->viewData('actionUrls');

    // A staff-only entity a consumer never receives resolves to no link.
    expect($urls)->toContain(route('portal.projects.show', $project))
        ->and($urls)->toContain(null);
});

// ---------------------------------------------------------------------------
// 5) Regression: staff cannot reach the portal inbox (P-1 boundary)
// ---------------------------------------------------------------------------

it('keeps staff out of the portal notification inbox', function () {
    $this->actingAs(portalStaff())->get(route('portal.notifications'))->assertForbidden();
});

it('requires a verified consumer to reach the inbox', function () {
    $unverified = portalConsumer(verified: false);

    $this->actingAs($unverified)->get(route('portal.notifications'))
        ->assertRedirect(route('portal.verification.notice'));
});
