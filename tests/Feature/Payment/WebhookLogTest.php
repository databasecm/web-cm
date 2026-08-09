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
use App\Services\Payment\PaymentWebhookLogger;
use App\Services\Payment\SimulatedGateway;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
| Fase G-5 — payment_webhook_logs (ADR-0013): an audit row per callback. The
| controller logs the REJECTED outcome; the job logs the final one (settled/
| noop/unknown_ref). Payload is stored REDACTED (no signature material), and a
| logging failure never breaks the settlement.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = app(SimulatedGateway::class);
});

/** A checked-out project with the checkout term charged (has a gateway_ref). */
function wlChargedProject(): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $project->refresh();
    app(PaymentService::class)->createCharge(
        $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole()
    );

    return $project;
}

function wlTerm(Project $project, DueCondition $due): Installment
{
    return $project->installments()->where('due_condition', $due->value)->sole();
}

function wlLog(): ?PaymentWebhookLog
{
    return PaymentWebhookLog::query()->latest('id')->first();
}

// ---------------------------------------------------------------------------
// Outcomes
// ---------------------------------------------------------------------------

it('logs a rejected callback (bad signature) as verified=false, outcome=rejected', function () {
    $payload = $this->gateway->callbackPayload(wlTerm(wlChargedProject(), DueCondition::Checkout));
    $payload['signature'] = 'forged';

    $this->postJson('/api/v1/payments/webhook', $payload)->assertStatus(401);

    $log = wlLog();
    expect($log->outcome)->toBe(PaymentWebhookLog::OUTCOME_REJECTED)
        ->and($log->verified)->toBeFalse();
});

it('logs a settled callback as outcome=settled', function () {
    $checkout = wlTerm(wlChargedProject(), DueCondition::Checkout);

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($checkout))->assertOk();

    expect(PaymentWebhookLog::where('outcome', PaymentWebhookLog::OUTCOME_SETTLED)->where('gateway_ref', $checkout->gateway_ref)->exists())->toBeTrue();
});

it('logs a replay on an already-paid term as outcome=noop', function () {
    $checkout = wlTerm(wlChargedProject(), DueCondition::Checkout);
    app(PaymentService::class)->pay($checkout); // already paid via the ledger

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($checkout))->assertOk();

    expect(PaymentWebhookLog::where('outcome', PaymentWebhookLog::OUTCOME_NOOP)->exists())->toBeTrue();
});

it('logs a locked pelunasan (§7) as outcome=noop', function () {
    $project = wlChargedProject();
    $bast = wlTerm($project, DueCondition::Bast);
    $bast->forceFill(['gateway_ref' => 'SIM-CHG-'.str_pad((string) $bast->id, 8, '0', STR_PAD_LEFT)])->save();

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($bast))->assertOk();

    expect(PaymentWebhookLog::where('outcome', PaymentWebhookLog::OUTCOME_NOOP)->where('gateway_ref', $bast->gateway_ref)->exists())->toBeTrue();
});

it('logs an unknown gateway_ref as outcome=unknown_ref', function () {
    // A validly-signed payload for a ref no installment carries.
    $ref = 'SIM-CHG-99999999';
    $this->postJson('/api/v1/payments/webhook', [
        'gateway_ref' => $ref, 'status' => 'paid', 'signature' => $this->gateway->sign($ref),
    ])->assertOk();

    expect(PaymentWebhookLog::where('outcome', PaymentWebhookLog::OUTCOME_UNKNOWN_REF)->where('gateway_ref', $ref)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Redaction — signature material never stored
// ---------------------------------------------------------------------------

it('stores the payload redacted (no signature material)', function () {
    $checkout = wlTerm(wlChargedProject(), DueCondition::Checkout);
    $payload = $this->gateway->callbackPayload($checkout);

    $this->postJson('/api/v1/payments/webhook', $payload)->assertOk();

    $log = PaymentWebhookLog::where('gateway_ref', $checkout->gateway_ref)->latest('id')->first();
    expect($log->payload['signature'])->toBe('[redacted]')
        ->and(json_encode($log->payload))->not->toContain($payload['signature']);
});

it('redacts a Midtrans signature_key too', function () {
    $redacted = app(PaymentWebhookLogger::class)->redact([
        'order_id' => 'CM-1', 'signature_key' => 'a-real-sha512', 'transaction_status' => 'settlement',
    ]);

    expect($redacted['signature_key'])->toBe('[redacted]')
        ->and($redacted['order_id'])->toBe('CM-1');
});

// ---------------------------------------------------------------------------
// A logging failure never breaks the settlement
// ---------------------------------------------------------------------------

it('settles even when writing the audit log fails', function () {
    $checkout = wlTerm(wlChargedProject(), DueCondition::Checkout);

    // Make the underlying log write fail (table gone) — the REAL logger must
    // swallow it internally so the settlement still completes.
    Schema::drop('payment_webhook_logs');

    $this->postJson('/api/v1/payments/webhook', $this->gateway->callbackPayload($checkout))->assertOk();

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1);
});
