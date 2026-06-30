<?php

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Exceptions\CheckoutException;
use App\Models\Project;
use App\Services\CheckoutService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = new CheckoutService;
});

/** A project finalised with a contract value, ready to check out. */
function contractedProject(string $contractValue): Project
{
    return Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => $contractValue]);
}

/** Sum the installment amounts as a 2-decimal string. */
function installmentSum(Project $project): string
{
    $sum = BigDecimal::zero();
    foreach ($project->installments()->get() as $i) {
        $sum = $sum->plus(BigDecimal::of((string) $i->amount));
    }

    return (string) $sum->toScale(2, RoundingMode::HALF_UP);
}

// ---------------------------------------------------------------------------
// Each scheme generates the right terms + due conditions
// ---------------------------------------------------------------------------

it('generates the termin3 schedule (30 checkout / 40 progress50 / 30 bast)', function () {
    $project = contractedProject('1000000.00');

    $this->service->checkout($project, PaymentScheme::Termin3);

    $terms = $project->installments()->get();
    expect($terms)->toHaveCount(3)
        ->and($terms[0]->due_condition)->toBe(DueCondition::Checkout)
        ->and($terms[0]->amount)->toBe('300000.00')
        ->and($terms[0]->status)->toBe(InstallmentStatus::Unlocked)
        ->and($terms[1]->due_condition)->toBe(DueCondition::Progress50)
        ->and($terms[1]->amount)->toBe('400000.00')
        ->and($terms[1]->status)->toBe(InstallmentStatus::Locked)
        ->and($terms[2]->due_condition)->toBe(DueCondition::Bast)
        ->and($terms[2]->amount)->toBe('300000.00')
        ->and($terms[2]->status)->toBe(InstallmentStatus::Locked)
        ->and($project->refresh()->payment_scheme)->toBe(PaymentScheme::Termin3)
        ->and($project->status)->toBe(ProjectStatus::Active);
});

it('generates the 50:50 schedule (checkout / bast)', function () {
    $project = contractedProject('1000000.00');

    $this->service->checkout($project, PaymentScheme::Fifty);

    $terms = $project->installments()->get();
    expect($terms)->toHaveCount(2)
        ->and($terms[0]->due_condition)->toBe(DueCondition::Checkout)
        ->and($terms[0]->amount)->toBe('500000.00')
        ->and($terms[1]->due_condition)->toBe(DueCondition::Bast)
        ->and($terms[1]->amount)->toBe('500000.00');
});

it('generates the lunas schedule (100% checkout, unlocked)', function () {
    $project = contractedProject('1000000.00');

    $this->service->checkout($project, PaymentScheme::Lunas);

    $terms = $project->installments()->get();
    expect($terms)->toHaveCount(1)
        ->and($terms[0]->due_condition)->toBe(DueCondition::Checkout)
        ->and($terms[0]->amount)->toBe('1000000.00')
        ->and($terms[0]->status)->toBe(InstallmentStatus::Unlocked);
});

// ---------------------------------------------------------------------------
// Σ installments == contract_value EXACTLY (no lost cents)
// ---------------------------------------------------------------------------

it('keeps Σ(installments) exactly equal to contract_value, even with awkward cents', function () {
    // 100.01 split 50/50 would naively round to 50.01 + 50.01 = 100.02; the
    // remainder approach must keep the total at 100.01.
    $fifty = contractedProject('100.01');
    $this->service->checkout($fifty, PaymentScheme::Fifty);
    expect(installmentSum($fifty))->toBe('100.01');

    // A non-round value across termin3.
    $termin3 = contractedProject('333333.33');
    $this->service->checkout($termin3, PaymentScheme::Termin3);
    expect(installmentSum($termin3))->toBe('333333.33');
});

it('unlocks only the checkout term; the rest start locked', function () {
    $project = contractedProject('900000.00');

    $this->service->checkout($project, PaymentScheme::Termin3);

    $unlocked = $project->installments()->where('status', InstallmentStatus::Unlocked->value)->get();
    expect($unlocked)->toHaveCount(1)
        ->and($unlocked->first()->due_condition)->toBe(DueCondition::Checkout);
});

// ---------------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------------

it('refuses checkout when there is no contract value (no approved RAB)', function () {
    $project = Project::factory()->create(['contract_value' => null]);

    expect(fn () => $this->service->checkout($project, PaymentScheme::Lunas))
        ->toThrow(CheckoutException::class);
});

it('refuses a second checkout', function () {
    $project = contractedProject('1000000.00');
    $this->service->checkout($project, PaymentScheme::Lunas);

    expect(fn () => $this->service->checkout($project->refresh(), PaymentScheme::Termin3))
        ->toThrow(CheckoutException::class);
});
