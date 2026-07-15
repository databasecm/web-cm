<?php

use App\Enums\Bidang;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\TransactionException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinanceReportService;
use App\Services\TransactionService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = app(TransactionService::class);
    $this->report = app(FinanceReportService::class);
});

function cbUser(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// ---------------------------------------------------------------------------
// Manual entry is created, tagged manual, attributed and audited
// ---------------------------------------------------------------------------

it('records a manual expense that is tagged manual, attributed and audited', function () {
    $finance = cbUser('finance');

    $txn = $this->service->recordManual(
        TransactionType::Expense,
        TransactionCategory::Operasional,
        '1250000',            // no decimals in → normalized to .00
        '2026-07-10',
        'Beli ATK kantor',
        $finance,
    );

    expect($txn->type)->toBe(TransactionType::Expense)
        ->and($txn->category)->toBe(TransactionCategory::Operasional)
        ->and($txn->reference_type)->toBe(Transaction::REF_MANUAL)
        ->and($txn->isManual())->toBeTrue()
        ->and((int) $txn->recorded_by)->toBe($finance->id)
        ->and(BigDecimal::of($txn->amount)->isEqualTo('1250000.00'))->toBeTrue();

    // Auditable (§6.6): the creation is journalled.
    $audit = AuditLog::where('entity', Transaction::class)
        ->where('entity_id', $txn->id)->where('action', 'created')->first();
    expect($audit)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// The guard refuses auto-sourced categories (anti double-count), allows the rest
// ---------------------------------------------------------------------------

it('refuses hand-entering an auto-sourced category, but allows the manual ones', function () {
    // Gaji (payroll), material (PO) and pembayaran_konsumen (installment) are
    // posted automatically — never by hand.
    expect(fn () => $this->service->recordManual(TransactionType::Expense, TransactionCategory::Gaji, '100', '2026-07-10'))
        ->toThrow(TransactionException::class)
        ->and(fn () => $this->service->recordManual(TransactionType::Expense, TransactionCategory::Material, '100', '2026-07-10'))
        ->toThrow(TransactionException::class)
        ->and(fn () => $this->service->recordManual(TransactionType::Income, TransactionCategory::PembayaranKonsumen, '100', '2026-07-10'))
        ->toThrow(TransactionException::class);

    // The manual set posts fine.
    expect($this->service->recordManual(TransactionType::Expense, TransactionCategory::Operasional, '100', '2026-07-10'))
        ->toBeInstanceOf(Transaction::class)
        ->and($this->service->recordManual(TransactionType::Income, TransactionCategory::Investor, '100', '2026-07-10'))
        ->toBeInstanceOf(Transaction::class)
        ->and($this->service->recordManual(TransactionType::Expense, TransactionCategory::Lainnya, '100', '2026-07-10'))
        ->toBeInstanceOf(Transaction::class);

    expect(Transaction::manual()->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Auto-sourced rows are immutable; only a manual row may be edited/deleted
// ---------------------------------------------------------------------------

it('locks auto-sourced rows against edit/delete, but a manual row is editable by its recorder and overseers', function () {
    $finance = cbUser('finance');
    $otherFinance = cbUser('finance');
    $owner = cbUser('owner');

    $auto = Transaction::factory()->create([
        'reference_type' => Transaction::REF_INSTALLMENT,
        'reference_id' => 1,
    ]);
    $manual = $this->service->recordManual(TransactionType::Expense, TransactionCategory::Operasional, '100', '2026-07-10', null, $finance);

    // Auto row: nobody can touch it (mirrors a real event).
    expect($finance->can('update', $auto))->toBeFalse()
        ->and($finance->can('delete', $auto))->toBeFalse()
        ->and($owner->can('update', $auto))->toBeFalse();

    // Manual row: the recorder and overseers may; a different Finance user may not.
    expect($finance->can('update', $manual))->toBeTrue()
        ->and($finance->can('delete', $manual))->toBeTrue()
        ->and($owner->can('update', $manual))->toBeTrue()          // overseer overrides ownership
        ->and($otherFinance->can('update', $manual))->toBeFalse(); // not the recorder, not an overseer
});

// ---------------------------------------------------------------------------
// RBAC — only Finance / Owner / Direktur see the cash book
// ---------------------------------------------------------------------------

it('exposes the cash book to Finance and overseers only', function () {
    foreach (['finance', 'owner', 'direktur'] as $name) {
        expect(cbUser($name)->can('viewAny', Transaction::class))->toBeTrue("{$name} must see the cash book");
    }

    foreach (['hr', 'manager', 'mandor', 'mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $actor = $name === 'manager'
            ? User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id'), 'bidang' => Bidang::Cufid])
            : cbUser($name);
        expect($actor->can('viewAny', Transaction::class))->toBeFalse("{$name} must NOT see the cash book")
            ->and($actor->can('create', Transaction::class))->toBeFalse("{$name} must NOT write the cash book");
    }
});

// ---------------------------------------------------------------------------
// Totals & saldo are BigDecimal-exact, and the period filter bounds them
// ---------------------------------------------------------------------------

it('totals income, expense, net and per-category composition exactly', function () {
    $finance = cbUser('finance');

    // In July.
    $this->service->recordManual(TransactionType::Income, TransactionCategory::Investor, '5000000.50', '2026-07-05', null, $finance);
    $this->service->recordManual(TransactionType::Expense, TransactionCategory::Operasional, '1200000.25', '2026-07-06', null, $finance);
    $this->service->recordManual(TransactionType::Expense, TransactionCategory::Lainnya, '300000.25', '2026-07-07', null, $finance);
    // Outside July — must be excluded from the period, but count toward saldo.
    $this->service->recordManual(TransactionType::Income, TransactionCategory::Lainnya, '999999.99', '2026-06-30', null, $finance);

    $july = $this->report->summary('2026-07-01', '2026-07-31');

    expect(BigDecimal::of($july['income'])->isEqualTo('5000000.50'))->toBeTrue()
        ->and(BigDecimal::of($july['expense'])->isEqualTo('1500000.50'))->toBeTrue() // 1200000.25 + 300000.25
        ->and(BigDecimal::of($july['net'])->isEqualTo('3500000.00'))->toBeTrue()
        ->and(BigDecimal::of($july['by_category']['income.investor'])->isEqualTo('5000000.50'))->toBeTrue()
        ->and(BigDecimal::of($july['by_category']['expense.operasional'])->isEqualTo('1200000.25'))->toBeTrue()
        ->and($july['by_category'])->not->toHaveKey('income.lainnya'); // June row excluded from July

    // Saldo (all time) folds in the June income.
    expect(BigDecimal::of($this->report->balance())->isEqualTo('4499999.99'))->toBeTrue(); // 3500000.00 + 999999.99
});
