<?php

use App\Enums\FinancingStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\FinancingException;
use App\Models\Financing;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancingService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = app(FinancingService::class);
});

function svcRoled(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// ---------------------------------------------------------------------------
// apply — owning consumer submits
// ---------------------------------------------------------------------------

it('lets the owning consumer apply for financing', function () {
    $konsumen = svcRoled('konsumen');
    $bank = svcRoled('mitra_pembiayaan');
    $project = Project::factory()->ownedBy($konsumen)->create();

    // Authorization gate (enforced by the caller in 4-5).
    expect($konsumen->can('applyFinancing', $project))->toBeTrue()
        ->and(svcRoled('konsumen')->can('applyFinancing', $project))->toBeFalse();

    $financing = $this->service->apply($project, $konsumen, $bank, '75000000');

    expect($financing->status)->toBe(FinancingStatus::Submitted)
        ->and((int) $financing->konsumen_id)->toBe($konsumen->id)
        ->and((int) $financing->bank_mitra_id)->toBe($bank->id)
        ->and($financing->amount)->toBe('75000000.00');
});

// ---------------------------------------------------------------------------
// transition — bank-owned lifecycle, legal moves logged
// ---------------------------------------------------------------------------

it('walks the lifecycle and logs each transition', function () {
    $bank = svcRoled('mitra_pembiayaan');
    $financing = Financing::factory()->forBank($bank)->create(); // submitted

    $this->service->transition($financing, FinancingStatus::DocsRequired, $bank, 'lengkapi dokumen');
    $this->service->transition($financing, FinancingStatus::Interview, $bank);
    $this->service->transition($financing, FinancingStatus::Approved, $bank);

    expect($financing->fresh()->status)->toBe(FinancingStatus::Approved)
        ->and($financing->statusLogs()->count())->toBe(3);

    // An illegal move is refused (guard from 4-1).
    expect(fn () => $this->service->transition($financing, FinancingStatus::Submitted, $bank))
        ->toThrow(FinancingException::class);

    // Disbursement cannot happen through transition() — it must go via disburse().
    $approved = Financing::factory()->status(FinancingStatus::Approved)->create();
    expect(fn () => $this->service->transition($approved, FinancingStatus::Disbursed, $bank))
        ->toThrow(FinancingException::class);
});

it('authorizes lifecycle management to the owning bank only', function () {
    $bankA = svcRoled('mitra_pembiayaan');
    $bankB = svcRoled('mitra_pembiayaan');
    $financing = Financing::factory()->forBank($bankA)->create();

    expect($bankA->can('manageLifecycle', $financing))->toBeTrue()
        ->and($bankB->can('manageLifecycle', $financing))->toBeFalse()
        ->and(svcRoled('konsumen')->can('manageLifecycle', $financing))->toBeFalse()
        ->and(svcRoled('manager')->can('manageLifecycle', $financing))->toBeFalse();
});

// ---------------------------------------------------------------------------
// disburse — only from approved, posts INVESTOR income, idempotent
// ---------------------------------------------------------------------------

it('disburses an approved financing and posts investor income once', function () {
    $bank = svcRoled('mitra_pembiayaan');
    $financing = Financing::factory()->forBank($bank)->status(FinancingStatus::Approved)
        ->create(['amount' => '80000000.00']);

    $txn = $this->service->disburse($financing, $bank);

    expect($financing->fresh()->status)->toBe(FinancingStatus::Disbursed)
        ->and($txn->type)->toBe(TransactionType::Income)
        ->and($txn->category)->toBe(TransactionCategory::Investor)
        ->and($txn->reference_type)->toBe(Transaction::REF_FINANCING)
        ->and((int) $txn->reference_id)->toBe($financing->id)
        ->and(BigDecimal::of($txn->amount)->isEqualTo('80000000.00'))->toBeTrue();

    // Idempotent: a second disbursement is refused, no duplicate income row.
    expect(fn () => $this->service->disburse($financing->fresh()))->toThrow(FinancingException::class)
        ->and(Transaction::forFinancings()->where('reference_id', $financing->id)->count())->toBe(1);
});

it('refuses to disburse a financing that is not approved', function () {
    $submitted = Financing::factory()->create(); // submitted
    expect(fn () => $this->service->disburse($submitted))->toThrow(FinancingException::class)
        ->and(Transaction::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Cash-book consistency + §6.5 (bank never mutates projects)
// ---------------------------------------------------------------------------

it('keeps investor income equal to the disbursed amount', function () {
    $financing = Financing::factory()->status(FinancingStatus::Approved)->create(['amount' => '123456789.00']);

    $this->service->disburse($financing);

    $incomeSum = Transaction::forFinancings()->where('reference_id', $financing->id)->get()
        ->reduce(fn (BigDecimal $c, Transaction $t) => $c->plus($t->amount), BigDecimal::zero());

    expect($incomeSum->isEqualTo($financing->fresh()->amount))->toBeTrue()
        ->and($incomeSum->isEqualTo('123456789.00'))->toBeTrue();
});

it('never lets the bank mutate projects throughout the flow (§6.5)', function () {
    $bank = svcRoled('mitra_pembiayaan');
    $financing = Financing::factory()->forBank($bank)->status(FinancingStatus::Approved)->create();

    // The bank drives the financing...
    expect($bank->can('manageLifecycle', $financing))->toBeTrue();

    // ...but has zero write access to the project or project creation.
    expect($bank->can('update', $financing->project))->toBeFalse()
        ->and($bank->can('delete', $financing->project))->toBeFalse()
        ->and($bank->can('create', Project::class))->toBeFalse();
});
