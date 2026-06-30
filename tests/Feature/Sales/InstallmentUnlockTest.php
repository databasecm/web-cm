<?php

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Exceptions\InstallmentException;
use App\Models\Installment;
use App\Models\Project;
use App\Services\CheckoutService;
use App\Services\ProgressService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->progress = new ProgressService;
});

/** A checked-out termin3 project (DP unlocked, progress50 + bast locked). */
function termin3Project(): Project
{
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    return $project->refresh();
}

function term(Project $project, DueCondition $due): Installment
{
    return $project->installments()->where('due_condition', $due->value)->sole();
}

// ---------------------------------------------------------------------------
// progress50: locked below 50, unlocked at ≥ 50 (idempotent)
// ---------------------------------------------------------------------------

it('keeps the progress term locked below 50% and opens it at ≥ 50%', function () {
    $project = termin3Project();
    expect(term($project, DueCondition::Progress50)->status)->toBe(InstallmentStatus::Locked);

    $this->progress->setProgress($project, 40);
    expect(term($project, DueCondition::Progress50)->status)->toBe(InstallmentStatus::Locked);

    $this->progress->setProgress($project, 50);
    expect(term($project, DueCondition::Progress50)->status)->toBe(InstallmentStatus::Unlocked);
});

it('is idempotent: crossing 50% repeatedly does not re-open or duplicate', function () {
    $project = termin3Project();

    $this->progress->setProgress($project, 60);
    $progressTerm = term($project, DueCondition::Progress50);
    expect($progressTerm->status)->toBe(InstallmentStatus::Unlocked);
    $unlockedUpdatedAt = $progressTerm->updated_at;

    // Going higher again opens nothing further (already unlocked).
    expect($this->progress->setProgress($project, 90))->not->toBeNull();
    $again = term($project, DueCondition::Progress50);
    expect($again->status)->toBe(InstallmentStatus::Unlocked)
        ->and($again->updated_at->equalTo($unlockedUpdatedAt))->toBeTrue()
        // still exactly one progress term
        ->and($project->installments()->where('due_condition', DueCondition::Progress50->value)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// bast (pelunasan): NEVER opens without a signed BAST — hard guard
// ---------------------------------------------------------------------------

it('keeps the bast term locked at any progress, even 100%', function () {
    $project = termin3Project();

    $this->progress->setProgress($project, 100);

    expect(term($project, DueCondition::Bast)->status)->toBe(InstallmentStatus::Locked);
});

it('rejects any direct attempt to unlock the bast term (BAST required)', function () {
    $project = termin3Project();
    $this->progress->setProgress($project, 100);
    $bast = term($project, DueCondition::Bast);

    expect(fn () => $this->progress->unlock($bast))->toThrow(InstallmentException::class);
    expect($bast->refresh()->status)->toBe(InstallmentStatus::Locked);
});

it('refuses to unlock a progress term before 50% is reached', function () {
    $project = termin3Project();
    $this->progress->setProgress($project, 20);

    expect(fn () => $this->progress->unlock(term($project, DueCondition::Progress50)))
        ->toThrow(InstallmentException::class);
});

// ---------------------------------------------------------------------------
// checkout term is unaffected (already unlocked from 2B-5)
// ---------------------------------------------------------------------------

it('leaves the checkout term unlocked throughout', function () {
    $project = termin3Project();
    expect(term($project, DueCondition::Checkout)->status)->toBe(InstallmentStatus::Unlocked);

    $this->progress->setProgress($project, 50);

    expect(term($project, DueCondition::Checkout)->status)->toBe(InstallmentStatus::Unlocked);
});

it('rejects an out-of-range progress value', function () {
    $project = termin3Project();

    expect(fn () => $this->progress->setProgress($project, 150))->toThrow(InvalidArgumentException::class);
});
