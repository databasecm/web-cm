<?php

use App\Enums\BastParty;
use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BastService;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\ProgressService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->pay = app(PaymentService::class);
});

function payProject(PaymentScheme $scheme = PaymentScheme::Termin3): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, $scheme);

    return $project->refresh();
}

function payTerm(Project $project, DueCondition $due): Installment
{
    return $project->installments()->where('due_condition', $due->value)->sole();
}

function payRoled(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// ---------------------------------------------------------------------------
// Paying an unlocked term → paid + a cash-book income row
// ---------------------------------------------------------------------------

it('pays an unlocked term and posts the income to the cash book', function () {
    $finance = payRoled('finance');
    $project = payProject();
    $checkout = payTerm($project, DueCondition::Checkout); // unlocked from checkout, 30% = 300000

    $txn = $this->pay->pay($checkout, $finance);

    expect($checkout->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and($checkout->paid_at)->not->toBeNull()
        ->and($txn->type)->toBe(TransactionType::Income)
        ->and($txn->category)->toBe(TransactionCategory::PembayaranKonsumen)
        ->and($txn->reference_type)->toBe(Transaction::REF_INSTALLMENT)
        ->and((int) $txn->reference_id)->toBe($checkout->id)
        ->and((int) $txn->recorded_by)->toBe($finance->id)
        ->and(BigDecimal::of($txn->amount)->isEqualTo('300000.00'))->toBeTrue()
        ->and(BigDecimal::of($txn->amount)->isEqualTo($checkout->amount))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Hard guard (§7): a locked term cannot be paid
// ---------------------------------------------------------------------------

it('refuses to pay a locked progress or pelunasan term', function () {
    $project = payProject();

    // progress50 locked (progress < 50)
    expect(fn () => $this->pay->pay(payTerm($project, DueCondition::Progress50)))
        ->toThrow(PaymentException::class);

    // pelunasan locked (no signed BAST)
    expect(fn () => $this->pay->pay(payTerm($project, DueCondition::Bast)))
        ->toThrow(PaymentException::class);

    // Nothing was posted to the cash book.
    expect(Transaction::count())->toBe(0)
        ->and(payTerm($project, DueCondition::Progress50)->status)->toBe(InstallmentStatus::Locked)
        ->and(payTerm($project, DueCondition::Bast)->status)->toBe(InstallmentStatus::Locked);
});

// ---------------------------------------------------------------------------
// Idempotent: a paid term cannot be paid twice
// ---------------------------------------------------------------------------

it('rejects a second payment and never doubles the cash book', function () {
    $project = payProject();
    $checkout = payTerm($project, DueCondition::Checkout);

    $this->pay->pay($checkout);

    expect(fn () => $this->pay->pay($checkout->refresh()))->toThrow(PaymentException::class)
        ->and(Transaction::forInstallments()->where('reference_id', $checkout->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Cash-book consistency: Σ income == Σ paid installments (across a full scheme)
// ---------------------------------------------------------------------------

it('keeps Σ income equal to Σ paid installments through a full termin3 cycle', function () {
    $project = payProject();

    // checkout (300k) payable now
    $this->pay->pay(payTerm($project, DueCondition::Checkout));

    // reach 50% → progress term (400k) unlocks → pay it
    app(ProgressService::class)->setProgress($project, 50);
    $this->pay->pay(payTerm($project, DueCondition::Progress50));

    // sign BAST → pelunasan (300k) unlocks → pay it
    $bast = app(BastService::class)->issue($project);
    app(BastService::class)->recordSignature($bast, BastParty::Company);
    app(BastService::class)->recordSignature($bast, BastParty::Customer);
    $this->pay->pay(payTerm($project, DueCondition::Bast));

    $paidSum = $project->installments()->where('status', 'paid')->get()
        ->reduce(fn (BigDecimal $c, Installment $i) => $c->plus($i->amount), BigDecimal::zero());

    $incomeSum = Transaction::forInstallments()
        ->whereIn('reference_id', $project->installments()->pluck('id'))
        ->get()
        ->reduce(fn (BigDecimal $c, Transaction $t) => $c->plus($t->amount), BigDecimal::zero());

    expect($incomeSum->isEqualTo($paidSum))->toBeTrue()
        ->and($incomeSum->isEqualTo('1000000.00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// RBAC: only Finance / Owner / Direktur may record a payment
// ---------------------------------------------------------------------------

it('authorizes recording a payment to Finance and overseers only', function () {
    foreach (['finance', 'owner', 'direktur'] as $name) {
        expect(Gate::forUser(payRoled($name))->allows('recordPayment'))->toBeTrue("{$name} should record payments");
    }

    foreach (['manager', 'hr', 'mitra_pembiayaan', 'mandor', 'konsumen'] as $name) {
        expect(Gate::forUser(payRoled($name))->allows('recordPayment'))->toBeFalse("{$name} must not record payments");
    }
});
