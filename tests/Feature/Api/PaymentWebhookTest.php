<?php

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Jobs\ProcessPaymentCallback;
use App\Models\Installment;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\Payment\SimulatedGateway;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = app(SimulatedGateway::class);
});

/** A checked-out project with the checkout term charged (has a gateway_ref). */
function webhookChargedProject(): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $project->refresh();

    $checkout = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->createCharge($checkout);

    return $project;
}

function webhookTerm(Project $project, DueCondition $due): Installment
{
    return $project->installments()->where('due_condition', $due->value)->sole();
}

// ---------------------------------------------------------------------------
// End-to-end: charge → signed callback → paid + income
// ---------------------------------------------------------------------------

it('settles a valid paid callback end to end', function () {
    $project = webhookChargedProject();
    $checkout = webhookTerm($project, DueCondition::Checkout);

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($checkout))
        ->assertOk()
        ->assertJsonPath('message', 'Diterima.');

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Anti-replay / idempotency
// ---------------------------------------------------------------------------

it('is idempotent: a duplicate callback pays once and posts one income', function () {
    $project = webhookChargedProject();
    $checkout = webhookTerm($project, DueCondition::Checkout);
    $payload = $this->gateway->callbackPayload($checkout);

    $this->postJson('/api/v1/payments/webhook', $payload)->assertOk();
    $this->postJson('/api/v1/payments/webhook', $payload)->assertOk(); // replay

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1);
});

it('is a no-op for a callback on an already-paid term', function () {
    $project = webhookChargedProject();
    $checkout = webhookTerm($project, DueCondition::Checkout);
    app(PaymentService::class)->pay($checkout); // already paid via the ledger

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($checkout))->assertOk();

    expect(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// §7 holds through the webhook — a locked term cannot be settled
// ---------------------------------------------------------------------------

it('refuses to settle a locked pelunasan even with a valid signature', function () {
    $project = webhookChargedProject();

    // A locked pelunasan cannot be charged legitimately; force a gateway_ref on
    // it to simulate a crafted callback and prove §7 still blocks settlement.
    $bast = webhookTerm($project, DueCondition::Bast);
    $bast->forceFill(['gateway_ref' => 'SIM-CHG-'.str_pad((string) $bast->id, 8, '0', STR_PAD_LEFT)])->save();

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($bast))->assertOk();

    expect($bast->refresh()->status)->toBe(InstallmentStatus::Locked)
        ->and(Transaction::forInstallments()->where('reference_id', $bast->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Invalid signature — rejected, no state change
// ---------------------------------------------------------------------------

it('rejects a callback with a bad signature and changes nothing', function () {
    $project = webhookChargedProject();
    $checkout = webhookTerm($project, DueCondition::Checkout);

    $payload = $this->gateway->callbackPayload($checkout);
    $payload['signature'] = 'forged';

    $this->postJson('/api/v1/payments/webhook', $payload)->assertStatus(401);

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Unlocked)
        ->and(Transaction::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Verified callbacks are processed on the queue
// ---------------------------------------------------------------------------

it('queues a verified callback for settlement', function () {
    Queue::fake();

    $project = webhookChargedProject();
    $checkout = webhookTerm($project, DueCondition::Checkout);

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($checkout))->assertOk();

    Queue::assertPushed(ProcessPaymentCallback::class, fn (ProcessPaymentCallback $job) => $job->paid === true
        && $job->gatewayRef === $checkout->gateway_ref);
});

it('does not queue anything for an invalid callback', function () {
    Queue::fake();

    $project = webhookChargedProject();
    $checkout = webhookTerm($project, DueCondition::Checkout);
    $payload = $this->gateway->callbackPayload($checkout);
    $payload['signature'] = 'forged';

    $this->postJson('/api/v1/payments/webhook', $payload)->assertStatus(401);

    Queue::assertNothingPushed();
});
