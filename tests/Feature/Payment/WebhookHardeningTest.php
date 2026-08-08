<?php

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Models\Installment;
use App\Models\Project;
use App\Services\CheckoutService;
use App\Services\Payment\SimulatedGateway;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Fase G-4 — webhook hardening (ADR-0013): IP allowlist + per-IP throttle on the
| public callback endpoint. These are abuse bounds BEFORE the controller; the
| signature (G-2) remains the sole basis of authenticity. Default gateway stays
| simulated, allowlist empty, so dev/CI are never blocked.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = app(SimulatedGateway::class);
});

/** A charged checkout term + its valid simulated callback payload. */
function whChargedPayload(): array
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $term = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    app(PaymentService::class)->createCharge($term);

    return app(SimulatedGateway::class)->callbackPayload($term);
}

// ---------------------------------------------------------------------------
// IP allowlist
// ---------------------------------------------------------------------------

it('allows any IP when the allowlist is empty (dev/simulated/CI not blocked)', function () {
    config(['payment.webhook.ips' => []]);

    // A valid simulated callback settles end to end — the IP gate did not block.
    $this->postJson('/api/v1/payments/webhook', whChargedPayload())
        ->assertOk()
        ->assertJsonPath('message', 'Diterima.');
});

it('lets a listed IP through', function () {
    config(['payment.webhook.ips' => ['127.0.0.1']]); // the test client IP

    $this->postJson('/api/v1/payments/webhook', whChargedPayload())->assertOk();
});

it('refuses an IP outside a populated allowlist with 403, before any settlement', function () {
    config(['payment.webhook.ips' => ['203.0.113.7']]); // not the test client IP

    $payload = whChargedPayload();
    $this->postJson('/api/v1/payments/webhook', $payload)->assertForbidden();

    // The term was never settled — refused before the controller.
    $term = Installment::query()->where('gateway_ref', $payload['gateway_ref'])->sole();
    expect($term->status)->toBe(InstallmentStatus::Unlocked);
});

// ---------------------------------------------------------------------------
// Throttle
// ---------------------------------------------------------------------------

it('throttles a flood of callbacks with 429 once the per-IP limit is exceeded', function () {
    config(['payment.webhook.ips' => [], 'payment.webhook.throttle.max_attempts' => 1, 'payment.webhook.throttle.decay_seconds' => 60]);

    // First request consumes the single allowed hit.
    $this->postJson('/api/v1/payments/webhook', whChargedPayload())->assertOk();
    // Second within the window → 429.
    $this->postJson('/api/v1/payments/webhook', whChargedPayload())->assertStatus(429);
});

// ---------------------------------------------------------------------------
// Signature remains the sole basis of authenticity
// ---------------------------------------------------------------------------

it('still rejects a bad signature even from an allowed IP (401)', function () {
    config(['payment.webhook.ips' => ['127.0.0.1']]);

    // IP passes the gate, but the payload signature is invalid → 401, no settle.
    $this->postJson('/api/v1/payments/webhook', [
        'gateway_ref' => 'SIM-CHG-00000001',
        'status' => 'paid',
        'signature' => 'not-a-valid-signature',
    ])->assertStatus(401);
});
