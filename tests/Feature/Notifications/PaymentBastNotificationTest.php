<?php

use App\Enums\BastParty;
use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Models\Installment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\PaymentPaidNotification;
use App\Notifications\RecipientResolver;
use App\Services\BastService;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function n3User(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $role)->value('id'),
        'bidang' => $bidang,
    ]);
}

/** A Cufid project owned by $owner, checked out (Termin3) → Active, checkout term unlocked. */
function n3Project(User $owner): Project
{
    $project = Project::factory()
        ->status(ProjectStatus::Rab)
        ->inBidang(Bidang::Cufid)
        ->ownedBy($owner)
        ->create(['contract_value' => '1000000.00']);

    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    return $project->refresh();
}

function n3CheckoutTerm(Project $project): Installment
{
    return $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
}

/** Count of a given event's notifications a user holds. */
function n3Count(User $user, string $event): int
{
    return $user->notifications()->where('data->event', $event)->count();
}

// ---------------------------------------------------------------------------
// E1 — payment.paid → owning consumer + Finance + overseers ONLY
// ---------------------------------------------------------------------------

it('notifies the consumer, Finance and overseers when a term is paid', function () {
    $owner = n3User('konsumen');
    $finance = n3User('finance');
    $ownerRole = n3User('owner');
    $direktur = n3User('direktur');
    $managerCufid = n3User('manager', Bidang::Cufid);
    $managerCc = n3User('manager', Bidang::Cc);
    $otherKonsumen = n3User('konsumen');

    $project = n3Project($owner);

    app(PaymentService::class)->pay(n3CheckoutTerm($project));

    // Entitled.
    foreach ([$owner, $finance, $ownerRole, $direktur] as $u) {
        expect(n3Count($u, 'payment.paid'))->toBe(1, "{$u->role->name} should be notified");
    }
    // Not entitled — a payment is not a Manager's or another consumer's concern.
    foreach ([$managerCufid, $managerCc, $otherKonsumen] as $u) {
        expect(n3Count($u, 'payment.paid'))->toBe(0, "{$u->role->name} must not be notified");
    }
});

it('keeps the money amount out of the payment body', function () {
    $owner = n3User('konsumen');
    n3User('finance');
    $project = n3Project($owner);
    $term = n3CheckoutTerm($project); // 30% of 1,000,000 = 300000

    app(PaymentService::class)->pay($term);

    $body = $owner->notifications()->where('data->event', 'payment.paid')->sole()->data['body'];

    // The project reference (#id) is fine; the amount must never appear.
    expect($body)
        ->not->toContain('300000')     // raw
        ->and($body)->not->toContain('300.000') // grouped
        ->and($body)->not->toContain('Rp')
        ->and($body)->not->toMatch('/\d[\d.,]*\.\d{2}\b/'); // any decimal money
});

// ---------------------------------------------------------------------------
// E2 — bast.issued → owning consumer + Manager of the project's bidang ONLY
// ---------------------------------------------------------------------------

it('notifies the consumer and the bidang Manager when a BAST is issued', function () {
    $owner = n3User('konsumen');
    $managerCufid = n3User('manager', Bidang::Cufid);
    $managerCc = n3User('manager', Bidang::Cc);
    $finance = n3User('finance');
    $direktur = n3User('direktur');
    $otherKonsumen = n3User('konsumen');

    $project = n3Project($owner);
    app(BastService::class)->issue($project);

    expect(n3Count($owner, 'bast.issued'))->toBe(1)
        ->and(n3Count($managerCufid, 'bast.issued'))->toBe(1);

    // Finance, overseers, a Manager of another bidang, another consumer: none.
    foreach ([$managerCc, $finance, $direktur, $otherKonsumen] as $u) {
        expect(n3Count($u, 'bast.issued'))->toBe(0, "{$u->role->name}/{$u->bidang?->value} must not be notified");
    }
});

// ---------------------------------------------------------------------------
// E3 — bast.signed → consumer + bidang Manager + Finance; once, on transition
// ---------------------------------------------------------------------------

it('notifies the consumer, bidang Manager and Finance when a BAST is signed', function () {
    $owner = n3User('konsumen');
    $managerCufid = n3User('manager', Bidang::Cufid);
    $managerCc = n3User('manager', Bidang::Cc);
    $finance = n3User('finance');
    $direktur = n3User('direktur');

    $project = n3Project($owner);
    $bast = app(BastService::class)->issue($project);

    // First party alone does NOT sign the BAST → no bast.signed yet.
    app(BastService::class)->recordSignature($bast, BastParty::Company);
    expect(n3Count($owner, 'bast.signed'))->toBe(0);

    // Second party completes it → transition fires E3 once.
    app(BastService::class)->recordSignature($bast->refresh(), BastParty::Customer);

    foreach ([$owner, $managerCufid, $finance] as $u) {
        expect(n3Count($u, 'bast.signed'))->toBe(1, "{$u->role->name} should be notified");
    }
    // A Manager of another bidang and overseers are not on the E3 map.
    expect(n3Count($managerCc, 'bast.signed'))->toBe(0)
        ->and(n3Count($direktur, 'bast.signed'))->toBe(0);

    // Re-signing an already-signed BAST notifies no one further (transition-only).
    app(BastService::class)->recordSignature($bast->refresh(), BastParty::Customer);
    expect(n3Count($owner, 'bast.signed'))->toBe(1);
});

// ---------------------------------------------------------------------------
// Idempotency — the same event dispatched twice yields one notification each
// ---------------------------------------------------------------------------

it('dispatches an event at most once per recipient', function () {
    $owner = n3User('konsumen');
    $finance = n3User('finance');
    $project = n3Project($owner);
    $term = n3CheckoutTerm($project);
    $term->update(['status' => InstallmentStatus::Paid, 'paid_at' => now()]); // isolate the dispatch

    $dispatcher = app(NotificationDispatcher::class);
    $make = fn (): PaymentPaidNotification => new PaymentPaidNotification($term);

    // Simulate a retry / double call.
    $dispatcher->dispatch('payment.paid', $term, $make);
    $dispatcher->dispatch('payment.paid', $term, $make);

    expect(n3Count($owner, 'payment.paid'))->toBe(1)
        ->and(n3Count($finance, 'payment.paid'))->toBe(1);
});

// ---------------------------------------------------------------------------
// Async + failure isolation — a notification fault never fails the payment
// ---------------------------------------------------------------------------

it('queues notifications and never fails the payment when notifying throws', function () {
    n3User('finance');
    $owner = n3User('konsumen');
    $project = n3Project($owner);
    $term = n3CheckoutTerm($project);

    // The notification is queued (async) — it implements ShouldQueue.
    expect(new PaymentPaidNotification($term))
        ->toBeInstanceOf(ShouldQueue::class);

    // Force recipient resolution to blow up; the dispatcher must swallow it.
    $this->app->instance(RecipientResolver::class, new class extends RecipientResolver
    {
        public function recipientsFor(string $event, Model $entity): Collection
        {
            throw new RuntimeException('notification backend down');
        }
    });

    $txn = app(PaymentService::class)->pay($term); // must NOT throw

    // Payment succeeded end to end despite the notification fault.
    expect($txn)->toBeInstanceOf(Transaction::class)
        ->and($term->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(Transaction::forInstallments()->where('reference_id', $term->id)->count())->toBe(1);
});
