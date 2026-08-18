<?php

use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Livewire\Portal\ProjectPayments;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** A consumer + their project with a contract value, ready to checkout. */
function payableProject(User $consumer, string $contractValue = '1000000'): Project
{
    return portalOwnedProject($consumer, ['contract_value' => $contractValue]);
}

// ---------------------------------------------------------------------------
// 1) Choose scheme → schedule generated (via the checkout service)
// ---------------------------------------------------------------------------

it('generates the 30/40/30 schedule when the consumer picks Termin3', function () {
    $me = portalConsumer();
    $project = payableProject($me, '1000000');

    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project])
        ->call('checkout', PaymentScheme::Termin3->value)
        ->assertHasNoErrors();

    $terms = $project->installments()->orderBy('term_no')->get();

    expect($terms)->toHaveCount(3)
        ->and((float) $terms[0]->amount)->toBe(300000.0)
        ->and($terms[0]->status)->toBe(InstallmentStatus::Unlocked)   // DP, due at checkout
        ->and((float) $terms[1]->amount)->toBe(400000.0)
        ->and($terms[1]->status)->toBe(InstallmentStatus::Locked)     // progress ≥50%
        ->and((float) $terms[2]->amount)->toBe(300000.0)
        ->and($terms[2]->status)->toBe(InstallmentStatus::Locked);    // pelunasan, after BAST (§7)
});

it('generates the right number of terms for each scheme', function (PaymentScheme $scheme, int $count) {
    $me = portalConsumer();
    $project = payableProject($me);

    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project])
        ->call('checkout', $scheme->value)
        ->assertHasNoErrors();

    expect($project->installments()->count())->toBe($count);
})->with([
    'Termin3' => [PaymentScheme::Termin3, 3],
    'Fifty' => [PaymentScheme::Fifty, 2],
    'Lunas' => [PaymentScheme::Lunas, 1],
]);

// ---------------------------------------------------------------------------
// 2) Pay an unlocked term → VA (createCharge), idempotent, never settles
// ---------------------------------------------------------------------------

it('raises a VA for an unlocked term and is idempotent', function () {
    $me = portalConsumer();
    $project = payableProject($me);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Lunas);
    $term = $project->installments()->firstOrFail(); // Unlocked (due at checkout)

    $component = Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project]);

    $component->call('charge', $term->id)->assertHasNoErrors();
    $term->refresh();
    $va = $term->va_number;

    expect($va)->not->toBeNull()
        ->and($term->gateway_ref)->not->toBeNull()
        ->and($term->status)->toBe(InstallmentStatus::Unlocked); // charge NEVER settles

    // Clicking pay again returns the SAME charge — no duplicate VA/ref.
    $component->call('charge', $term->id)->assertHasNoErrors();
    $term->refresh();

    expect($term->va_number)->toBe($va);
});

// ---------------------------------------------------------------------------
// 3) §7 intact from the portal: a locked pelunasan term cannot be charged
// ---------------------------------------------------------------------------

it('cannot charge a locked pelunasan term before BAST (§7)', function () {
    $me = portalConsumer();
    $project = payableProject($me);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Termin3);
    $pelunasan = $project->installments()->where('term_no', 3)->firstOrFail(); // bast, Locked

    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project])
        ->call('charge', $pelunasan->id)
        ->assertHasNoErrors(); // handled as a surfaced error, not a crash

    $pelunasan->refresh();

    expect($pelunasan->status)->toBe(InstallmentStatus::Locked)
        ->and($pelunasan->gateway_ref)->toBeNull()   // no VA raised
        ->and($pelunasan->va_number)->toBeNull();
});

// ---------------------------------------------------------------------------
// 4) pending → paid is WEBHOOK-only; then the receipt is downloadable
// ---------------------------------------------------------------------------

it('reflects paid only after the webhook settles, then allows the receipt', function () {
    $me = portalConsumer();
    $project = payableProject($me);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Lunas);
    $term = $project->installments()->firstOrFail();

    // Portal raises the VA but must NOT settle.
    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project])
        ->call('charge', $term->id);
    expect($term->refresh()->status)->toBe(InstallmentStatus::Unlocked);

    // Receipt is not available before payment.
    $this->actingAs($me)->get(route('portal.installments.receipt', $term))->assertForbidden();

    // The webhook settles (the ONLY settlement path) → paid.
    app(PaymentService::class)->pay($term);
    expect($term->refresh()->status)->toBe(InstallmentStatus::Paid);

    // Now the owner can download the receipt.
    $this->actingAs($me)->get(route('portal.installments.receipt', $term))
        ->assertOk()
        ->assertDownload();
});

// ---------------------------------------------------------------------------
// 5) Ownership: charge / receipt of another consumer's term → 403
// ---------------------------------------------------------------------------

it('forbids charging another consumer’s installment (no bypass)', function () {
    $me = portalConsumer();
    $mine = payableProject($me);

    $other = portalConsumer();
    $theirs = payableProject($other);
    app(CheckoutService::class)->checkout($theirs, PaymentScheme::Lunas);
    $theirTerm = $theirs->installments()->firstOrFail();

    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $mine])
        ->call('charge', $theirTerm->id)
        ->assertForbidden();

    expect($theirTerm->refresh()->gateway_ref)->toBeNull();
});

it('forbids downloading another consumer’s receipt', function () {
    $other = portalConsumer();
    $theirs = payableProject($other);
    app(CheckoutService::class)->checkout($theirs, PaymentScheme::Lunas);
    $term = $theirs->installments()->firstOrFail();
    app(PaymentService::class)->pay($term);

    $this->actingAs(portalConsumer())
        ->get(route('portal.installments.receipt', $term))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 6) InstallmentPolicy::charge — owner yes, non-owner no
// ---------------------------------------------------------------------------

it('gates InstallmentPolicy::charge to the owning consumer', function () {
    $me = portalConsumer();
    $project = payableProject($me);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Lunas);
    $term = $project->installments()->firstOrFail();

    expect($me->can('charge', $term))->toBeTrue()
        ->and(portalConsumer()->can('charge', $term))->toBeFalse()
        ->and(portalStaff()->can('charge', $term))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7) Regression: staff cannot reach the portal payment surfaces
// ---------------------------------------------------------------------------

it('keeps staff out of the payments page and receipt route', function () {
    $consumer = portalConsumer();
    $project = payableProject($consumer);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Lunas);
    $term = $project->installments()->firstOrFail();
    app(PaymentService::class)->pay($term);

    $this->actingAs(portalStaff())->get(route('portal.projects.payments', $project))->assertForbidden();
    $this->actingAs(portalStaff())->get(route('portal.installments.receipt', $term))->assertForbidden();
});
