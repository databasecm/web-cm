<?php

use App\Enums\FinancingStatus;
use App\Enums\TransactionCategory;
use App\Exceptions\FinancingException;
use App\Models\AuditLog;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancingDocumentService;
use App\Services\FinancingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function dodUser(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// (a) §6.5 — a bank is read-only on projects across EVERY surface -------------

it('DoD(a): the financing bank can never mutate a project', function () {
    $bank = dodUser('mitra_pembiayaan');
    $financing = Financing::factory()->forBank($bank)->create();
    $project = $financing->project;

    // Drives its own financing...
    expect($bank->can('manageLifecycle', $financing))->toBeTrue();

    // ...but has zero write access to the project (dashboard, portal, API alike).
    expect($bank->can('update', $project))->toBeFalse()
        ->and($bank->can('delete', $project))->toBeFalse()
        ->and($bank->can('create', Project::class))->toBeFalse()
        ->and($bank->can('checkout', $project))->toBeFalse();
});

// (b) disbursement → exactly one investor income, idempotent ------------------

it('DoD(b): disbursement posts exactly one investor income and is idempotent', function () {
    $financing = Financing::factory()->status(FinancingStatus::Approved)->create(['amount' => '50000000.00']);

    app(FinancingService::class)->disburse($financing);
    expect(fn () => app(FinancingService::class)->disburse($financing->fresh()))->toThrow(FinancingException::class);

    $income = Transaction::forFinancings()->where('reference_id', $financing->id)->get();
    expect($income)->toHaveCount(1)
        ->and($income->first()->category)->toBe(TransactionCategory::Investor)
        ->and($income->first()->amount)->toBe('50000000.00');
});

// (c) one active financing per project ----------------------------------------

it('DoD(c): a project holds only one active financing at a time', function () {
    $project = Project::factory()->create();
    Financing::factory()->forProject($project)->create();

    expect(fn () => Financing::factory()->forProject($project)->create())->toThrow(FinancingException::class);
});

// (d) sensitive documents never leak -----------------------------------------

it('DoD(d): document file is redacted in audit and hidden from non-parties', function () {
    $bank = dodUser('mitra_pembiayaan');
    $financing = Financing::factory()->forBank($bank)->create();
    app(FinancingDocumentService::class)->upload($financing, 'Slip Gaji', 'financing/secret-payslip.pdf');
    $doc = $financing->documents()->first();

    // Not in the audit trail.
    foreach (AuditLog::where('entity', FinancingDocument::class)->get() as $audit) {
        expect(json_encode($audit->after ?? []))->not->toContain('secret-payslip.pdf');
    }

    // Not visible to Managers / Finance (only owner consumer, owning bank, O/D).
    expect(dodUser('manager')->can('view', $doc))->toBeFalse()
        ->and(dodUser('finance')->can('view', $doc))->toBeFalse()
        ->and($bank->can('view', $doc))->toBeTrue();
});

// (e) a consumer can never touch bank authority -------------------------------

it('DoD(e): a consumer cannot manage the lifecycle or review documents', function () {
    $konsumen = dodUser('konsumen');
    $financing = Financing::factory()->forProject(Project::factory()->ownedBy($konsumen)->create())->create();
    $doc = FinancingDocument::factory()->forFinancing($financing)->create();

    expect($konsumen->can('manageLifecycle', $financing))->toBeFalse()
        ->and($konsumen->can('review', $doc))->toBeFalse();
});
