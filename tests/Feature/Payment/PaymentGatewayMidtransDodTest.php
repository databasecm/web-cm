<?php

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Models\Installment;
use App\Models\PaymentWebhookLog;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\Payment\MidtransGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SimulatedGateway;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
|------------------------------------------------------------------------------
| Gateway (Midtrans) — Definition of Done (living specification, ADR-0012/0013)
|------------------------------------------------------------------------------
| The closing gate for the real payment gateway. Runs with PAYMENT_GATEWAY=
| midtrans + a TEST ServerKey in config and Http::fake throughout — NO network,
| NO real credential. It proves, end to end on the Midtrans path, that:
|   (a) full flow: createCharge → stored charge → signed callback → paid + income
|   (b) anti-replay: a duplicate valid callback pays once (second → noop)
|   (c) §7: a valid callback for a locked pelunasan never settles (noop)
|   (d) signature: a tampered callback is 401, no state, logged rejected
|   (e) conversion: charge gross_amount is an exact integer of rupiah
|   (f) the default (simulated) path stays intact — CI needs no Midtrans secret
|
| verify_status (the status-API re-confirmation, G-2) is toggled OFF here so the
| signature→settle path is deterministic without a network call; that layer has
| its own coverage in the G-2 suite.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config([
        'payment.gateway' => 'midtrans',
        'payment.midtrans.server_key' => 'SB-Mid-server-TESTKEY-do-not-use',
        'payment.midtrans.is_production' => false,
        'payment.midtrans.bank' => 'bca',
        'payment.midtrans.order_prefix' => 'CM',
        'payment.midtrans.verify_status' => false,
        'payment.webhook.ips' => [], // allow all in the DoD
    ]);
});

/** A fake Core API bank_transfer (BCA) charge success response. */
function dgChargeResponse(string $orderId, string $va = '80770000000001'): array
{
    return [
        'status_code' => '201',
        'order_id' => $orderId,
        'gross_amount' => '300000.00',
        'payment_type' => 'bank_transfer',
        'transaction_status' => 'pending',
        'va_numbers' => [['bank' => 'bca', 'va_number' => $va]],
    ];
}

/** A correctly SHA512-signed Midtrans notification for an order. */
function dgCallback(string $orderId, string $status = 'settlement', string $gross = '300000.00', string $statusCode = '200'): array
{
    $serverKey = (string) config('payment.midtrans.server_key');

    return [
        'order_id' => $orderId,
        'status_code' => $statusCode,
        'gross_amount' => $gross,
        'transaction_status' => $status,
        'signature_key' => hash('sha512', $orderId.$statusCode.$gross.$serverKey),
    ];
}

/** A checked-out project (Termin3) with the checkout term charged via Midtrans. */
function dgChargedProject(): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $project->refresh();

    $checkout = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    Http::fake(['*/v2/charge' => Http::response(dgChargeResponse("CM-{$checkout->id}"), 201)]);
    app(PaymentService::class)->createCharge($checkout);

    return $project->refresh();
}

function dgTerm(Project $project, DueCondition $due): Installment
{
    return $project->installments()->where('due_condition', $due->value)->sole();
}

// ---------------------------------------------------------------------------
// (a) Full flow on the Midtrans path
// ---------------------------------------------------------------------------

it('(a) settles end to end: charge → signed Midtrans callback → paid + income', function () {
    $checkout = dgTerm(dgChargedProject(), DueCondition::Checkout);

    // Charge stored the order_id as gateway_ref (the bridge).
    expect($checkout->gateway_ref)->toBe("CM-{$checkout->id}");

    $this->postJson('/api/v1/payments/webhook', dgCallback($checkout->gateway_ref))
        ->assertOk()
        ->assertJsonPath('message', 'Diterima.');

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1)
        ->and(PaymentWebhookLog::where('gateway_ref', $checkout->gateway_ref)->where('outcome', PaymentWebhookLog::OUTCOME_SETTLED)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// (b) Anti-replay on the Midtrans path
// ---------------------------------------------------------------------------

it('(b) pays once for a duplicated valid callback; the replay is a noop', function () {
    $checkout = dgTerm(dgChargedProject(), DueCondition::Checkout);
    $payload = dgCallback($checkout->gateway_ref);

    $this->postJson('/api/v1/payments/webhook', $payload)->assertOk();
    $this->postJson('/api/v1/payments/webhook', $payload)->assertOk(); // replay

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1)
        ->and(PaymentWebhookLog::where('gateway_ref', $checkout->gateway_ref)->where('outcome', PaymentWebhookLog::OUTCOME_SETTLED)->count())->toBe(1)
        ->and(PaymentWebhookLog::where('gateway_ref', $checkout->gateway_ref)->where('outcome', PaymentWebhookLog::OUTCOME_NOOP)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// (c) §7 holds on the Midtrans path — no back door via the real gateway
// ---------------------------------------------------------------------------

it('(c) refuses to settle a locked pelunasan even with a valid Midtrans signature', function () {
    $project = dgChargedProject();
    $bast = dgTerm($project, DueCondition::Bast); // locked — no signed BAST

    // Simulate a crafted callback: force a gateway_ref onto the locked term.
    $bast->forceFill(['gateway_ref' => "CM-{$bast->id}"])->save();

    $this->postJson('/api/v1/payments/webhook', dgCallback($bast->gateway_ref))->assertOk();

    expect($bast->refresh()->status)->toBe(InstallmentStatus::Locked)
        ->and(Transaction::forInstallments()->where('reference_id', $bast->id)->count())->toBe(0)
        ->and(PaymentWebhookLog::where('gateway_ref', $bast->gateway_ref)->where('outcome', PaymentWebhookLog::OUTCOME_NOOP)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// (d) Signature is decisive — a tampered callback changes nothing
// ---------------------------------------------------------------------------

it('(d) rejects a tampered Midtrans callback with 401, no state change, logged rejected', function () {
    $checkout = dgTerm(dgChargedProject(), DueCondition::Checkout);

    $payload = dgCallback($checkout->gateway_ref);
    $payload['gross_amount'] = '1.00'; // changed after signing → signature stale

    $this->postJson('/api/v1/payments/webhook', $payload)->assertStatus(401);

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Unlocked)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(0)
        ->and(PaymentWebhookLog::where('gateway_ref', $checkout->gateway_ref)->where('outcome', PaymentWebhookLog::OUTCOME_REJECTED)->where('verified', false)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// (e) gross_amount conversion — exact integer of rupiah (regression G-3)
// ---------------------------------------------------------------------------

it('(e) charges Midtrans with an exact integer gross_amount', function () {
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Lunas); // one term = 1,000,000.00
    $term = $project->installments()->sole();
    Http::fake(['*/v2/charge' => Http::response(dgChargeResponse("CM-{$term->id}"), 201)]);

    app(PaymentService::class)->createCharge($term);

    Http::assertSent(fn ($request) => $request['transaction_details']['gross_amount'] === 1000000
        && ! str_contains((string) $request['transaction_details']['gross_amount'], '.'));
});

// ---------------------------------------------------------------------------
// (f) The default (simulated) path stays intact — no Midtrans secret needed
// ---------------------------------------------------------------------------

it('(f) keeps the simulated gateway working when selected, with no HTTP', function () {
    config(['payment.gateway' => 'simulated']);
    Http::fake(); // nothing should be sent

    expect(app(PaymentGateway::class))->toBeInstanceOf(SimulatedGateway::class);

    // A full simulated charge → callback → settle, without any network call.
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $project->refresh();
    $checkout = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();

    $sim = app(SimulatedGateway::class);
    app(PaymentService::class)->createCharge($checkout);
    $this->postJson('/api/v1/payments/webhook', $sim->callbackPayload($checkout->refresh()))->assertOk();

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid);
    Http::assertNothingSent();
});

it('(f) resolves the Midtrans gateway when selected', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(MidtransGateway::class); // beforeEach set midtrans
});
