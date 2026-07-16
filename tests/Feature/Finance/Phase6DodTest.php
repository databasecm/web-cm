<?php

/*
|--------------------------------------------------------------------------
| Phase 6 Specification (living documentation) — HR, Payroll & Finance
|--------------------------------------------------------------------------
|
| The consolidated Definition-of-Done gate for Phase 6. Each section is a clause
| of the phase's invariants; the assertions make the rule explicit and pin it
| across EVERY surface (service, cash book, Mandor API, Filament, policy).
|
| Per-concern coverage also lives in PayrollGenerateTest, PayrollPaymentTest,
| CashBookTest, ProjectPnlTest, PurchaseOrderTest, MandorMaterialTest and the
| Employee/Payroll/PO/Transaction resource tests; this file is the single-glance
| guarantee.
|
| Invariants:
|   (a) Payroll accuracy — only 'hadir' pays; BigDecimal gross; Mon–Sat incl.
|       Saturday; inactive/monthly workers excluded from the weekly daily run.
|   (b) Money idempotency — re-generate / re-pay / re-receive never double.
|   (c) ADR-0016 — a paid payroll locks its period's attendance everywhere.
|   (d) Segregation of duties (§6.3) — cross-role rejections hold.
|   (e) One official path per expense — gaji only via payroll, material only via
|       PO; the manual cash book refuses both.
|   (f) Cash-book integrity — auto rows read-only; honest per-project P&L.
|   (g) Mandor has no cash — field material input posts nothing.
|   (h) Scope — finance data is Finance/O-D only; employees are Mandor-bidang.
|
*/

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\AttendanceException;
use App\Exceptions\PayrollException;
use App\Exceptions\PurchaseOrderException;
use App\Exceptions\TransactionException;
use App\Filament\Pages\FinanceDashboard;
use App\Filament\Resources\TransactionResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Material;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CheckoutService;
use App\Services\FinanceReportService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\PurchaseOrderService;
use App\Services\TransactionService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Payroll week Mon 2026-07-06 .. Sat 2026-07-11.
const DOD6_START = '2026-07-06';
const DOD6_END = '2026-07-11';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function dodP6User(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

/** A received PO on `$project` totalling `$total` (one line). */
function dodP6ReceivedPo(Project $project, string $total, User $by): PurchaseOrder
{
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create(['total' => '0.00']);
    $po->items()->create(['description' => 'Semen', 'unit' => 'sak', 'quantity' => '1.00', 'unit_price' => $total, 'subtotal' => '0.00']);
    app(PurchaseOrderService::class)->order($po->fresh(), $by);
    app(PurchaseOrderService::class)->receive($po->fresh(), $by);

    return $po->fresh();
}

// ===========================================================================
// (a) PAYROLL ACCURACY — only 'hadir' pays; exact BigDecimal; Saturday counts
// ===========================================================================

it('(a) pays only attended days, exact and Saturday-inclusive, excluding inactive/monthly workers', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $att = app(AttendanceService::class);

    // Daily worker: hadir Mon/Tue + Sat(period_end), izin Wed, alpa Thu → 3 paid days.
    $worker = Employee::factory()->inBidang(Bidang::Cufid)->type(EmployeeType::Harian)->create(['daily_wage' => '100000.00']);
    $att->record($worker, $project, '2026-07-06', AttendanceStatus::Hadir);
    $att->record($worker, $project, '2026-07-07', AttendanceStatus::Hadir);
    $att->record($worker, $project, '2026-07-08', AttendanceStatus::Izin);
    $att->record($worker, $project, '2026-07-09', AttendanceStatus::Alpa);
    $att->record($worker, $project, '2026-07-11', AttendanceStatus::Hadir); // Saturday = period_end

    // Excluded: an inactive daily worker and a monthly worker (both attended).
    $inactive = Employee::factory()->inBidang(Bidang::Cufid)->status(EmployeeStatus::Nonaktif)->create(['daily_wage' => '100000.00']);
    $monthly = Employee::factory()->inBidang(Bidang::Cufid)->type(EmployeeType::Bulanan)->create(['daily_wage' => '100000.00']);
    // (inactive attendance can't be recorded via the service; a monthly worker is
    // filtered out of the weekly daily run regardless of attendance.)
    $att->record($monthly, $project, '2026-07-06', AttendanceStatus::Hadir);

    $payroll = app(PayrollService::class)->generate(DOD6_START, DOD6_END);
    $slips = $payroll->payslips;

    expect($slips)->toHaveCount(1) // only the active daily worker
        ->and((int) $slips->first()->employee_id)->toBe($worker->id)
        ->and($slips->first()->days_present)->toBe(3)                       // izin/alpa never pay; Saturday counts
        ->and(BigDecimal::of((string) $slips->first()->gross)->isEqualTo('300000.00'))->toBeTrue()
        ->and(BigDecimal::of((string) $slips->first()->net)->isEqualTo('300000.00'))->toBeTrue()
        ->and($payroll->payslips()->whereIn('employee_id', [$inactive->id, $monthly->id])->count())->toBe(0);
});

// ===========================================================================
// (b) MONEY IDEMPOTENCY — re-generate / re-pay / re-receive never double
// ===========================================================================

it('(b) never doubles money on re-generate, re-pay, or re-receive', function () {
    $finance = dodP6User('finance');
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $att = app(AttendanceService::class);
    $worker = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    foreach (['2026-07-06', '2026-07-07'] as $d) {
        $att->record($worker, $project, $d, AttendanceStatus::Hadir);
    }

    // Re-generate a draft → same single payslip, not duplicated.
    app(PayrollService::class)->generate(DOD6_START, DOD6_END);
    $payroll = app(PayrollService::class)->generate(DOD6_START, DOD6_END);
    expect($payroll->payslips()->count())->toBe(1);

    // Re-pay → refused, exactly one gaji expense.
    app(PayrollService::class)->pay($payroll, $finance);
    expect(fn () => app(PayrollService::class)->pay($payroll->fresh(), $finance))->toThrow(PayrollException::class);
    expect(Transaction::forPayrolls()->where('reference_id', $payroll->id)->count())->toBe(1)
        ->and(BigDecimal::of((string) Transaction::forPayrolls()->sum('amount'))->isEqualTo('200000.00'))->toBeTrue();

    // Re-receive a PO → refused, exactly one material expense.
    $po = dodP6ReceivedPo($project, '500000.00', $finance);
    expect(fn () => app(PurchaseOrderService::class)->receive($po->fresh(), $finance))->toThrow(PurchaseOrderException::class);
    expect(Transaction::forPurchaseOrders()->where('reference_id', $po->id)->count())->toBe(1)
        ->and(BigDecimal::of((string) Transaction::forPurchaseOrders()->sum('amount'))->isEqualTo('500000.00'))->toBeTrue();
});

// ===========================================================================
// (c) ADR-0016 — a paid payroll freezes its period's attendance everywhere
// ===========================================================================

it('(c) locks the paid period attendance across service and Mandor API, other periods open', function () {
    $finance = dodP6User('finance');
    $mandor = dodP6User('mandor', Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $worker = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    $other = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    $att = app(AttendanceService::class);

    $existing = $att->record($worker, $project, '2026-07-06', AttendanceStatus::Hadir);
    $payroll = app(PayrollService::class)->generate(DOD6_START, DOD6_END);
    app(PayrollService::class)->pay($payroll, $finance);

    // Service: no new in-period row, no correction.
    expect(fn () => $att->record($other, $project, '2026-07-08', AttendanceStatus::Hadir))->toThrow(AttendanceException::class)
        ->and(fn () => $att->correct($existing, AttendanceStatus::Izin))->toThrow(AttendanceException::class);

    // Mandor API: an in-period item is rejected as period-locked.
    Sanctum::actingAs($mandor);
    $this->postJson('/api/v1/mandor/attendances/sync', ['items' => [[
        'client_id' => (string) Str::uuid(),
        'employee_id' => $other->id,
        'project_id' => $project->id,
        'date' => '2026-07-09',
        'status' => 'hadir',
    ]]])->assertOk()->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('meta.rejected', 1);

    // A different (unpaid) period stays open.
    expect($att->record($other, $project, '2026-07-13', AttendanceStatus::Hadir))->toBeInstanceOf(Attendance::class);
});

// ===========================================================================
// (d) SEGREGATION OF DUTIES (§6.3) — cross-role rejections hold
// ===========================================================================

it('(d) enforces segregation of duties across payroll, employees and PO', function () {
    $hr = dodP6User('hr');
    $finance = dodP6User('finance');
    $manager = dodP6User('manager', Bidang::Cufid);
    $payroll = Payroll::factory()->create();
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();

    // Payroll: HR generates but cannot pay; Finance pays but cannot generate.
    expect($hr->can('generatePayroll', Payroll::class))->toBeTrue()
        ->and($hr->can('pay', $payroll))->toBeFalse()
        ->and($finance->can('pay', $payroll))->toBeTrue()
        ->and($finance->can('generatePayroll', Payroll::class))->toBeFalse();

    // Employees: Finance never manages workers (it only pays).
    expect($finance->can('viewAny', Employee::class))->toBeFalse()
        ->and($finance->can('create', Employee::class))->toBeFalse()
        ->and($hr->can('create', Employee::class))->toBeTrue();

    // PO: a Manager orders (own bidang) but never receives; Finance receives.
    $po = PurchaseOrder::factory()->forProject($project)->status(PurchaseOrderStatus::Draft)->create();
    $po->items()->create(['description' => 'X', 'unit' => 'u', 'quantity' => '1.00', 'unit_price' => '1000.00', 'subtotal' => '1000.00']);
    expect($manager->can('order', $po))->toBeTrue()
        ->and($manager->can('receive', $po))->toBeFalse();
    app(PurchaseOrderService::class)->order($po->fresh(), $manager);
    expect($manager->can('receive', $po->fresh()))->toBeFalse()
        ->and($finance->can('receive', $po->fresh()))->toBeTrue();
});

// ===========================================================================
// (e) ONE OFFICIAL PATH per expense — gaji only via payroll, material via PO
// ===========================================================================

it('(e) allows gaji only from payroll and material only from PO; manual refuses both', function () {
    $service = app(TransactionService::class);

    // The manual cash book refuses the auto-sourced expense categories.
    expect(fn () => $service->recordManual(TransactionType::Expense, TransactionCategory::Gaji, '100', DOD6_END))
        ->toThrow(TransactionException::class)
        ->and(fn () => $service->recordManual(TransactionType::Expense, TransactionCategory::Material, '100', DOD6_END))
        ->toThrow(TransactionException::class);

    // The only gaji row comes from a payroll payout; the only material row from a received PO.
    $finance = dodP6User('finance');
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $worker = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    app(AttendanceService::class)->record($worker, $project, '2026-07-06', AttendanceStatus::Hadir);
    app(PayrollService::class)->pay(app(PayrollService::class)->generate(DOD6_START, DOD6_END), $finance);
    dodP6ReceivedPo($project, '500000.00', $finance);

    $gaji = Transaction::where('category', TransactionCategory::Gaji->value)->get();
    $material = Transaction::where('category', TransactionCategory::Material->value)->get();
    expect($gaji)->toHaveCount(1)->and($gaji->first()->reference_type)->toBe(Transaction::REF_PAYROLL)
        ->and($material)->toHaveCount(1)->and($material->first()->reference_type)->toBe(Transaction::REF_PO);
});

// ===========================================================================
// (f) CASH-BOOK INTEGRITY — auto rows read-only; honest per-project P&L
// ===========================================================================

it('(f) keeps auto rows read-only and per-project P&L honest (gaji unallocated)', function () {
    $finance = dodP6User('finance');
    $project = Project::factory()->inBidang(Bidang::Cufid)->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);

    // Income: a paid termin (pembayaran_konsumen) tied to the project.
    (new CheckoutService)->checkout($project, PaymentScheme::Lunas);
    $term = $project->installments()->where('due_condition', DueCondition::Checkout->value)->sole();
    $income = app(PaymentService::class)->pay($term, $finance);

    // Expense: a received PO (material) tied to the project, and payroll gaji (no project).
    $poTxn = Transaction::forPurchaseOrders()->where('reference_id', dodP6ReceivedPo($project, '300000.00', $finance)->id)->sole();
    $worker = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    app(AttendanceService::class)->record($worker, $project, '2026-07-06', AttendanceStatus::Hadir);
    $gaji = app(PayrollService::class)->pay(app(PayrollService::class)->generate(DOD6_START, DOD6_END), $finance);

    // Auto-sourced rows are immutable in the cash book (no manual edit/delete).
    foreach ([$income, $poTxn, $gaji] as $auto) {
        expect($finance->can('update', $auto))->toBeFalse()
            ->and($finance->can('delete', $auto))->toBeFalse();
    }

    // Categories map to the right direction.
    expect($income->type)->toBe(TransactionType::Income)
        ->and($poTxn->type)->toBe(TransactionType::Expense)
        ->and($gaji->type)->toBe(TransactionType::Expense);

    // Per-project P&L: income − project-linked expense; gaji is unallocated overhead,
    // never attributed to the project.
    $pnl = app(FinanceReportService::class)->profitLossByProject();
    expect(BigDecimal::of($pnl['projects'][$project->id]['income'])->isEqualTo('1000000.00'))->toBeTrue()
        ->and(BigDecimal::of($pnl['projects'][$project->id]['expense'])->isEqualTo('300000.00'))->toBeTrue() // PO only, not gaji
        ->and(BigDecimal::of($pnl['projects'][$project->id]['net'])->isEqualTo('700000.00'))->toBeTrue()
        ->and(BigDecimal::of($pnl['unallocated']['expense'])->isEqualTo('100000.00'))->toBeTrue();          // the gaji
});

// ===========================================================================
// (g) MANDOR HAS NO CASH — field material input posts nothing
// ===========================================================================

it('(g) posts zero cash-book transactions when a Mandor adds a field material', function () {
    Sanctum::actingAs(dodP6User('mandor', Bidang::Cufid));

    $this->postJson('/api/v1/mandor/materials', [
        'name' => 'Paku', 'unit' => 'kg', 'price' => '18000',
    ])->assertCreated();

    expect(Material::count())->toBe(1)
        ->and(Transaction::count())->toBe(0); // catalog only — never the cash book
});

// ===========================================================================
// (h) SCOPE — finance data is Finance/O-D only; employees are Mandor-bidang
// ===========================================================================

it('(h) confines finance data to Finance/O-D and employees to the Mandor bidang', function () {
    // Cash book + dashboard: Finance and overseers only.
    foreach (['finance', 'owner', 'direktur'] as $name) {
        $this->actingAs(dodP6User($name));
        expect(TransactionResource::canViewAny())->toBeTrue("{$name} sees the cash book")
            ->and(FinanceDashboard::canAccess())->toBeTrue("{$name} sees the dashboard");
    }
    foreach (['hr', 'manager', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        $this->actingAs(dodP6User($name, in_array($name, ['manager', 'mandor'], true) ? Bidang::Cufid : null));
        expect(TransactionResource::canViewAny())->toBeFalse("{$name} must not see the cash book")
            ->and(FinanceDashboard::canAccess())->toBeFalse("{$name} must not see the dashboard");
    }

    // Employees: a Mandor sees its own bidang, never another's.
    $mandor = dodP6User('mandor', Bidang::Cufid);
    $mine = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $theirs = Employee::factory()->inBidang(Bidang::Cc)->create();
    expect($mandor->can('view', $mine))->toBeTrue()
        ->and($mandor->can('view', $theirs))->toBeFalse();
});
