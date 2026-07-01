<?php

use App\Enums\BastParty;
use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Enums\TransactionType;
use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\BastService;
use App\Services\CheckoutService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SimulatedGateway;
use App\Services\PaymentService;
use App\Services\ProgressService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->payments = app(PaymentService::class);
});

function gwProject(PaymentScheme $scheme = PaymentScheme::Termin3): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, $scheme);

    return $project->refresh();
}

function gwTerm(Project $project, DueCondition $due): Installment
{
    return $project->installments()->where('due_condition', $due->value)->sole();
}

// ---------------------------------------------------------------------------
// Container binding — simulated gateway is the credential-free default
// ---------------------------------------------------------------------------

it('binds the simulated gateway as the default PaymentGateway', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(SimulatedGateway::class);
});

// ---------------------------------------------------------------------------
// createCharge — unlocked term gets a deterministic VA/ref
// ---------------------------------------------------------------------------

it('creates a deterministic charge for an unlocked term and stores the reference', function () {
    $project = gwProject();
    $checkout = gwTerm($project, DueCondition::Checkout); // 30% = 300000, unlocked

    $instruction = $this->payments->createCharge($checkout);

    expect($instruction->status)->toBe('pending')
        ->and($instruction->vaNumber)->toBe('8808'.str_pad((string) $checkout->id, 10, '0', STR_PAD_LEFT))
        ->and($instruction->gatewayRef)->toBe('SIM-CHG-'.str_pad((string) $checkout->id, 8, '0', STR_PAD_LEFT))
        ->and(BigDecimal::of($instruction->amount)->isEqualTo('300000.00'))->toBeTrue();

    $checkout->refresh();
    expect($checkout->va_number)->toBe($instruction->vaNumber)
        ->and($checkout->gateway_ref)->toBe($instruction->gatewayRef)
        ->and($checkout->status)->toBe(InstallmentStatus::Unlocked); // still pending, not paid
});

// ---------------------------------------------------------------------------
// Guard §7 — a locked term cannot be charged
// ---------------------------------------------------------------------------

it('refuses to charge a locked progress or pelunasan term', function () {
    $project = gwProject();

    expect(fn () => $this->payments->createCharge(gwTerm($project, DueCondition::Progress50)))
        ->toThrow(PaymentException::class);
    expect(fn () => $this->payments->createCharge(gwTerm($project, DueCondition::Bast)))
        ->toThrow(PaymentException::class);

    expect(gwTerm($project, DueCondition::Progress50)->gateway_ref)->toBeNull()
        ->and(gwTerm($project, DueCondition::Bast)->gateway_ref)->toBeNull();
});

// ---------------------------------------------------------------------------
// Idempotent — one unlocked term, one active charge
// ---------------------------------------------------------------------------

it('is idempotent: re-charging a term returns the same reference', function () {
    $project = gwProject();
    $checkout = gwTerm($project, DueCondition::Checkout);

    $first = $this->payments->createCharge($checkout);
    $ref = $checkout->refresh()->gateway_ref;

    $second = $this->payments->createCharge($checkout->refresh());

    expect($second->gatewayRef)->toBe($first->gatewayRef)
        ->and($second->vaNumber)->toBe($first->vaNumber)
        ->and($checkout->refresh()->gateway_ref)->toBe($ref); // unchanged
});

// ---------------------------------------------------------------------------
// Simulated settlement drives the real ledger path (regression on 3-4)
// ---------------------------------------------------------------------------

it('settles a simulated payment through PaymentService::pay', function () {
    $project = gwProject();
    $checkout = gwTerm($project, DueCondition::Checkout);
    $this->payments->createCharge($checkout);

    /** @var SimulatedGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $txn = $gateway->simulatePaymentReceived($checkout);

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and($txn->type)->toBe(TransactionType::Income)
        ->and(BigDecimal::of($txn->amount)->isEqualTo('300000.00'))->toBeTrue();

    // Paid term cannot be charged again.
    expect(fn () => $this->payments->createCharge($checkout->refresh()))->toThrow(PaymentException::class);

    // No duplicate income row for this installment.
    expect(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// verifyCallback — parses the settlement (for the Fase 3-6 webhook)
// ---------------------------------------------------------------------------

it('verifies a signed callback payload into a settlement', function () {
    /** @var SimulatedGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $ref = 'SIM-CHG-00000042';
    $sig = $gateway->sign($ref);

    $paid = $gateway->verifyCallback(['gateway_ref' => $ref, 'status' => 'paid', 'signature' => $sig]);
    expect($paid->gatewayRef)->toBe($ref)->and($paid->paid)->toBeTrue();

    $pending = $gateway->verifyCallback(['gateway_ref' => $ref, 'status' => 'pending', 'signature' => $sig]);
    expect($pending->paid)->toBeFalse();

    // A bad signature is rejected.
    expect(fn () => $gateway->verifyCallback(['gateway_ref' => $ref, 'status' => 'paid', 'signature' => 'nope']))
        ->toThrow(PaymentException::class);
});

// ---------------------------------------------------------------------------
// Charge across a full termin3 cycle uses the same guard as pay()
// ---------------------------------------------------------------------------

it('can charge each term once it is unlocked across a termin3 cycle', function () {
    $project = gwProject();

    // checkout charge now
    expect($this->payments->createCharge(gwTerm($project, DueCondition::Checkout))->gatewayRef)->not->toBeEmpty();

    // progress term after 50%
    app(ProgressService::class)->setProgress($project, 50);
    expect($this->payments->createCharge(gwTerm($project, DueCondition::Progress50))->gatewayRef)->not->toBeEmpty();

    // pelunasan after a signed BAST
    $bast = app(BastService::class)->issue($project);
    app(BastService::class)->recordSignature($bast, BastParty::Company);
    app(BastService::class)->recordSignature($bast, BastParty::Customer);
    expect($this->payments->createCharge(gwTerm($project, DueCondition::Bast))->gatewayRef)->not->toBeEmpty();
});
