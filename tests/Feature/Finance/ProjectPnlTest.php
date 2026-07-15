<?php

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\FinancingStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Employee;
use App\Models\Financing;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CheckoutService;
use App\Services\FinanceReportService;
use App\Services\FinancingService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\TransactionService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function pnlRoled(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// ---------------------------------------------------------------------------
// Auto-sourced rows carry their project
// ---------------------------------------------------------------------------

it('links a paid termin to its project', function () {
    $project = Project::factory()->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);
    $checkout = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();

    $txn = app(PaymentService::class)->pay($checkout, pnlRoled('finance'));

    expect((int) $txn->project_id)->toBe($project->id)
        ->and($txn->category)->toBe(TransactionCategory::PembayaranKonsumen);
});

it('links a financing disbursement to its project', function () {
    $bank = pnlRoled('mitra_pembiayaan');
    $project = Project::factory()->create();
    $financing = Financing::factory()->forProject($project)->forBank($bank)
        ->status(FinancingStatus::Approved)->create(['amount' => '50000000.00']);

    $txn = app(FinancingService::class)->disburse($financing, $bank);

    expect((int) $txn->project_id)->toBe($project->id)
        ->and($txn->category)->toBe(TransactionCategory::Investor);
});

// ---------------------------------------------------------------------------
// Salary (gaji) stays unallocated — one payroll spans many projects
// ---------------------------------------------------------------------------

it('leaves the payroll salary expense with no project (unallocated overhead)', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $att = app(AttendanceService::class);
    foreach (['2026-07-06', '2026-07-07'] as $d) {
        $att->record($employee, $project, $d, AttendanceStatus::Hadir);
    }
    app(PayrollService::class)->generate('2026-07-06', '2026-07-11');
    $payroll = Payroll::sole();

    $txn = app(PayrollService::class)->pay($payroll, pnlRoled('finance'));

    expect($txn->category)->toBe(TransactionCategory::Gaji)
        ->and($txn->project_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// A manual entry may (or may not) carry a project
// ---------------------------------------------------------------------------

it('lets a manual entry link to a project, and defaults to none', function () {
    $finance = pnlRoled('finance');
    $project = Project::factory()->create();
    $svc = app(TransactionService::class);

    $linked = $svc->recordManual(TransactionType::Expense, TransactionCategory::Operasional, '100000', '2026-07-10', null, $finance, $project->id);
    $general = $svc->recordManual(TransactionType::Expense, TransactionCategory::Operasional, '50000', '2026-07-10', null, $finance);

    expect((int) $linked->project_id)->toBe($project->id)
        ->and($general->project_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// P&L per project = Σ income(project) − Σ expense(project); gaji unallocated
// ---------------------------------------------------------------------------

it('computes per-project profit and loss exactly, excluding unallocated gaji', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    $report = app(FinanceReportService::class);

    // Project A: 1_000_000 in, 300_000.50 out → net 699_999.50
    Transaction::factory()->create(['type' => TransactionType::Income, 'category' => TransactionCategory::PembayaranKonsumen, 'amount' => '1000000.00', 'project_id' => $a->id]);
    Transaction::factory()->create(['type' => TransactionType::Expense, 'category' => TransactionCategory::Material, 'amount' => '300000.50', 'project_id' => $a->id]);
    // Project B: 200_000 out only → net -200_000
    Transaction::factory()->create(['type' => TransactionType::Expense, 'category' => TransactionCategory::Operasional, 'amount' => '200000.00', 'project_id' => $b->id]);
    // Gaji, no project → must land in unallocated, never on A or B.
    Transaction::factory()->create(['type' => TransactionType::Expense, 'category' => TransactionCategory::Gaji, 'amount' => '450000.00', 'project_id' => null]);

    $pnl = $report->profitLossByProject();

    expect(BigDecimal::of($pnl['projects'][$a->id]['income'])->isEqualTo('1000000.00'))->toBeTrue()
        ->and(BigDecimal::of($pnl['projects'][$a->id]['expense'])->isEqualTo('300000.50'))->toBeTrue()
        ->and(BigDecimal::of($pnl['projects'][$a->id]['net'])->isEqualTo('699999.50'))->toBeTrue()
        ->and(BigDecimal::of($pnl['projects'][$b->id]['net'])->isEqualTo('-200000.00'))->toBeTrue()
        // Gaji is NOT attributed to any project.
        ->and(BigDecimal::of($pnl['projects'][$a->id]['expense'])->isEqualTo('300000.50'))->toBeTrue()
        ->and($pnl['projects'][$b->id]['income'])->toBe('0.00')
        // Unallocated captures the gaji expense.
        ->and(BigDecimal::of($pnl['unallocated']['expense'])->isEqualTo('450000.00'))->toBeTrue()
        ->and(BigDecimal::of($pnl['unallocated']['net'])->isEqualTo('-450000.00'))->toBeTrue();
});
