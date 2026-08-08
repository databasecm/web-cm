<?php

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\Project;
use App\Services\CheckoutService;
use App\Services\Payment\MidtransGateway;
use App\Services\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
| Fase G-3 — MidtransGateway::createCharge: a Core API bank_transfer VA charge
| via the HTTP client (no SDK). Http::fake throughout — no real network. The
| §7 guard and "one charge per term" idempotency live in PaymentService (3-5);
| this gateway only calls Midtrans and maps the response.
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
    ]);
    $this->gw = new MidtransGateway;
});

/** An UNLOCKED checkout term (30% of the contract) on a fresh project. */
function mcTerm(string $contract = '1000000.00'): Installment
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => $contract]);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    return $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
}

/** A fake Core API bank_transfer (BCA) success response. */
function mcResponse(string $orderId, string $va = '12345678901'): array
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

// ---------------------------------------------------------------------------
// Happy path — a correct charge request + a mapped instruction
// ---------------------------------------------------------------------------

it('opens a VA charge with a correct request and maps the response', function () {
    $term = mcTerm();
    $orderId = "CM-{$term->id}";
    Http::fake(['*/v2/charge' => Http::response(mcResponse($orderId), 201)]);

    $instruction = $this->gw->createCharge($term);

    // Response mapped to the instruction.
    expect($instruction->vaNumber)->toBe('12345678901')
        ->and($instruction->gatewayRef)->toBe($orderId)
        ->and($instruction->amount)->toBe('300000.00')
        ->and($instruction->status)->toBe('pending');

    // Request built correctly: sandbox charge URL, Basic auth, order_id = ref,
    // gross_amount an INTEGER of rupiah (no decimals).
    Http::assertSent(function ($request) use ($orderId) {
        return $request->url() === 'https://api.sandbox.midtrans.com/v2/charge'
            && $request->hasHeader('Authorization')
            && $request['transaction_details']['order_id'] === $orderId
            && $request['transaction_details']['gross_amount'] === 300000
            && $request['bank_transfer']['bank'] === 'bca';
    });
});

it('sends gross_amount as an exact integer of rupiah (no decimal drift)', function () {
    // Lunas scheme → a single term equal to the whole contract (1,000,000.00).
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Lunas);
    $term = $project->installments()->sole();
    Http::fake(['*/v2/charge' => Http::response(mcResponse("CM-{$term->id}"), 201)]);

    $this->gw->createCharge($term);

    Http::assertSent(fn ($request) => $request['transaction_details']['gross_amount'] === 1000000
        && ! str_contains((string) $request['transaction_details']['gross_amount'], '.'));
});

// ---------------------------------------------------------------------------
// Idempotency (regression 3-5) — one charge per term, no duplicate POST
// ---------------------------------------------------------------------------

it('charges a term only once — a second call returns the same instruction, no re-POST', function () {
    $term = mcTerm();
    Http::fake(['*/v2/charge' => Http::response(mcResponse("CM-{$term->id}"), 201)]);

    $first = app(PaymentService::class)->createCharge($term);
    $second = app(PaymentService::class)->createCharge($term->refresh());

    expect($second->gatewayRef)->toBe($first->gatewayRef)
        ->and($second->vaNumber)->toBe($first->vaNumber);
    Http::assertSentCount(1); // the gateway was hit once; the replay reused the stored charge
});

// ---------------------------------------------------------------------------
// Gateway errors — clear exception, no fake instruction, term unchanged
// ---------------------------------------------------------------------------

it('raises a clear exception on a non-2xx gateway response', function () {
    $term = mcTerm();
    Http::fake(['*/v2/charge' => Http::response(['status_code' => '500'], 500)]);

    expect(fn () => $this->gw->createCharge($term))->toThrow(PaymentException::class);
});

it('raises on a malformed response that carries no VA', function () {
    $term = mcTerm();
    Http::fake(['*/v2/charge' => Http::response(['order_id' => "CM-{$term->id}", 'transaction_status' => 'pending'], 201)]);

    expect(fn () => $this->gw->createCharge($term))->toThrow(PaymentException::class);
});

it('leaves the term untouched through PaymentService when the gateway fails', function () {
    $term = mcTerm();
    Http::fake(['*/v2/charge' => Http::response([], 500)]);

    expect(fn () => app(PaymentService::class)->createCharge($term))->toThrow(PaymentException::class);

    $term->refresh();
    expect($term->gateway_ref)->toBeNull()
        ->and($term->va_number)->toBeNull()
        ->and($term->status)->toBe(InstallmentStatus::Unlocked); // still payable, no fake charge stored
});

// ---------------------------------------------------------------------------
// Simulated gateway still the default path when selected (no HTTP)
// ---------------------------------------------------------------------------

it('uses the simulated gateway without any HTTP when selected', function () {
    config(['payment.gateway' => 'simulated']);
    Http::fake(); // record: nothing should be sent
    $term = mcTerm();

    $instruction = app(PaymentService::class)->createCharge($term);

    expect($instruction->gatewayRef)->toStartWith('SIM-CHG-');
    Http::assertNothingSent();
});
