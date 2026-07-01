<?php

use App\Enums\BastParty;
use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Exceptions\BastException;
use App\Exceptions\InstallmentException;
use App\Models\Bast;
use App\Models\Installment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\BastService;
use App\Services\CheckoutService;
use App\Services\ProgressService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->bast = app(BastService::class);
    $this->progress = app(ProgressService::class);
});

function activeProject(PaymentScheme $scheme, ?User $konsumen = null, ?Bidang $bidang = null): Project
{
    $factory = Project::factory()->status(ProjectStatus::Rab);
    if ($konsumen) {
        $factory = $factory->ownedBy($konsumen);
    }
    if ($bidang) {
        $factory = $factory->inBidang($bidang);
    }
    $project = $factory->create(['contract_value' => '1000000.00']);

    (new CheckoutService)->checkout($project, $scheme);

    return $project->refresh();
}

function bastTerm(Project $project): ?Installment
{
    return $project->installments()->where('due_condition', DueCondition::Bast->value)->first();
}

function roledUser(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// issue — active project only, one per project
// ---------------------------------------------------------------------------

it('issues a draft BAST only for an active project, once', function () {
    $draftProject = Project::factory()->status(ProjectStatus::Rab)->create();
    expect(fn () => $this->bast->issue($draftProject))->toThrow(BastException::class);

    $project = activeProject(PaymentScheme::Termin3);
    $bast = $this->bast->issue($project);

    expect($bast->status->value)->toBe('draft')
        ->and($project->bast->is($bast))->toBeTrue();

    // one per project (1—1)
    expect(fn () => $this->bast->issue($project))->toThrow(BastException::class);
});

// ---------------------------------------------------------------------------
// Signing flow → unlock the pelunasan (the core of 3-2)
// ---------------------------------------------------------------------------

it('unlocks the pelunasan only once BOTH parties have signed', function () {
    $project = activeProject(PaymentScheme::Termin3);
    $bast = $this->bast->issue($project);

    // one signature → not signed, pelunasan stays locked
    $this->bast->recordSignature($bast, BastParty::Company);
    expect($bast->isSigned())->toBeFalse()
        ->and(bastTerm($project)->status)->toBe(InstallmentStatus::Locked);

    // second signature → signed, pelunasan unlocks
    $this->bast->recordSignature($bast, BastParty::Customer);
    expect($bast->refresh()->isSigned())->toBeTrue()
        ->and($bast->signed_at)->not->toBeNull()
        ->and(bastTerm($project)->status)->toBe(InstallmentStatus::Unlocked);
});

it('is idempotent: a signed BAST opens the pelunasan exactly once', function () {
    $project = activeProject(PaymentScheme::Termin3);
    $bast = $this->bast->issue($project);
    $this->bast->recordSignature($bast, BastParty::Company);
    $this->bast->recordSignature($bast, BastParty::Customer);

    $term = bastTerm($project);
    expect($term->status)->toBe(InstallmentStatus::Unlocked);
    $unlockedAt = $term->updated_at;

    // Signing again, and re-running the opener directly, changes nothing.
    $this->bast->recordSignature($bast, BastParty::Customer);
    expect($this->progress->openBastInstallments($project->refresh()))->toBe(0);

    $again = bastTerm($project);
    expect($again->status)->toBe(InstallmentStatus::Unlocked)
        ->and($again->updated_at->equalTo($unlockedAt))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Hard §7 guard — no unlock/pay without a signed BAST
// ---------------------------------------------------------------------------

it('keeps the pelunasan locked and rejects unlock without a signed BAST', function () {
    $project = activeProject(PaymentScheme::Termin3);

    // No BAST at all → still rejected.
    expect(fn () => $this->progress->unlock(bastTerm($project)))->toThrow(InstallmentException::class);

    // Issued but only company-signed → not signed → still rejected.
    $bast = $this->bast->issue($project);
    $this->bast->recordSignature($bast, BastParty::Company);

    expect(fn () => $this->progress->unlock(bastTerm($project)))->toThrow(InstallmentException::class)
        ->and(bastTerm($project)->status)->toBe(InstallmentStatus::Locked);
});

// ---------------------------------------------------------------------------
// All three schemes: pelunasan opens only after a signed BAST
// ---------------------------------------------------------------------------

it('gates the pelunasan behind a signed BAST across termin3 and fifty', function (PaymentScheme $scheme) {
    $project = activeProject($scheme);
    $bast = $this->bast->issue($project);

    expect(bastTerm($project)->status)->toBe(InstallmentStatus::Locked);

    $this->bast->recordSignature($bast, BastParty::Company);
    $this->bast->recordSignature($bast, BastParty::Customer);

    expect(bastTerm($project)->status)->toBe(InstallmentStatus::Unlocked);
})->with([
    'termin 30/40/30' => PaymentScheme::Termin3,
    '50:50' => PaymentScheme::Fifty,
]);

it('has no BAST-gated term under lunas (fully paid at checkout)', function () {
    $project = activeProject(PaymentScheme::Lunas);

    expect(bastTerm($project))->toBeNull()
        ->and($project->installments()->where('due_condition', DueCondition::Checkout->value)->sole()->status)
        ->toBe(InstallmentStatus::Unlocked);

    // Issuing + signing a BAST is harmless: there is simply nothing to open.
    $bast = $this->bast->issue($project);
    $this->bast->recordSignature($bast, BastParty::Company);
    $this->bast->recordSignature($bast, BastParty::Customer);

    expect($this->progress->openBastInstallments($project))->toBe(0);
});

// ---------------------------------------------------------------------------
// Authorization — who may issue / sign (endpoints arrive in 3-3)
// ---------------------------------------------------------------------------

it('authorizes issuing/company-signing to the managing staff, customer-signing to the owner', function () {
    $konsumen = roledUser('konsumen');
    $project = activeProject(PaymentScheme::Termin3, $konsumen, Bidang::Cufid);
    $bast = $this->bast->issue($project);

    $manager = roledUser('manager', Bidang::Cufid);
    $otherManager = roledUser('manager', Bidang::Cc);
    $owner = roledUser('owner');
    $otherKonsumen = roledUser('konsumen');

    // issue (project-scoped gate) — managing staff only
    expect(Gate::forUser($manager)->allows('issueBast', $project))->toBeTrue()
        ->and(Gate::forUser($otherManager)->allows('issueBast', $project))->toBeFalse()
        ->and(Gate::forUser($konsumen)->allows('issueBast', $project))->toBeFalse();

    // company signature — managing staff (Manager of the bidang, or overseer)
    expect(Gate::forUser($manager)->allows('signCompany', $bast))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('signCompany', $bast))->toBeTrue()
        ->and(Gate::forUser($otherManager)->allows('signCompany', $bast))->toBeFalse()
        ->and(Gate::forUser($konsumen)->allows('signCompany', $bast))->toBeFalse();

    // customer signature — the owning consumer only
    expect(Gate::forUser($konsumen)->allows('signCustomer', $bast))->toBeTrue()
        ->and(Gate::forUser($otherKonsumen)->allows('signCustomer', $bast))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('signCustomer', $bast))->toBeFalse();
});
