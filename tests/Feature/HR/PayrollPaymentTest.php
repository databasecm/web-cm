<?php

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\AttendanceException;
use App\Exceptions\PayrollException;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Payroll week Mon 2026-07-06 .. Sat 2026-07-11.
const PP_START = '2026-07-06';
const PP_END = '2026-07-11';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->payroll = app(PayrollService::class);
    $this->attendance = app(AttendanceService::class);
});

function ppRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

/** A generated payroll for one worker with `$present` attended days. */
function generatedPayroll(AttendanceService $att, PayrollService $svc, Employee $employee, Project $project, int $present): Payroll
{
    $dates = ['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10', '2026-07-11'];
    foreach (array_slice($dates, 0, $present) as $d) {
        $att->record($employee, $project, $d, AttendanceStatus::Hadir);
    }

    return $svc->generate(PP_START, PP_END);
}

// ---------------------------------------------------------------------------
// Payment posts a salary expense equal to Σ net
// ---------------------------------------------------------------------------

it('pays a payroll and posts a salary expense equal to the total net', function () {
    $finance = ppRoled('finance');
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $a = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    $b = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '120000.00']);

    foreach (['2026-07-06', '2026-07-07', '2026-07-08'] as $d) {
        $this->attendance->record($a, $project, $d, AttendanceStatus::Hadir); // 3 × 150000 = 450000
    }
    foreach (['2026-07-06', '2026-07-07'] as $d) {
        $this->attendance->record($b, $project, $d, AttendanceStatus::Hadir); // 2 × 120000 = 240000
    }
    $payroll = $this->payroll->generate(PP_START, PP_END);

    $expected = BigDecimal::of('450000.00')->plus('240000.00'); // 690000.00
    $txn = $this->payroll->pay($payroll, $finance);

    expect($payroll->fresh()->status->value)->toBe('paid')
        ->and($payroll->fresh()->paid_at)->not->toBeNull()
        ->and($txn->type)->toBe(TransactionType::Expense)
        ->and($txn->category)->toBe(TransactionCategory::Gaji)
        ->and($txn->reference_type)->toBe(Transaction::REF_PAYROLL)
        ->and((int) $txn->reference_id)->toBe($payroll->id)
        ->and(BigDecimal::of($txn->amount)->isEqualTo($expected))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Idempotent — a second payment is refused, no duplicate expense
// ---------------------------------------------------------------------------

it('refuses a second payment and never doubles the expense', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $payroll = generatedPayroll($this->attendance, $this->payroll, $employee, $project, 6);

    $this->payroll->pay($payroll);
    expect(fn () => $this->payroll->pay($payroll->fresh()))->toThrow(PayrollException::class);

    expect(Transaction::forPayrolls()->where('reference_id', $payroll->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// ADR-0016 — a paid period's attendance is frozen (all surfaces)
// ---------------------------------------------------------------------------

it('locks the period attendance after payroll is paid, from every surface', function () {
    $mandor = ppRoled('mandor', Bidang::Cufid);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    $other = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();

    $existing = $this->attendance->record($employee, $project, '2026-07-06', AttendanceStatus::Hadir);
    $payroll = $this->payroll->generate(PP_START, PP_END);
    $this->payroll->pay($payroll);

    // Service: cannot add a new in-period attendance, nor correct an existing one.
    expect(fn () => $this->attendance->record($other, $project, '2026-07-08', AttendanceStatus::Hadir))
        ->toThrow(AttendanceException::class)
        ->and(fn () => $this->attendance->correct($existing, AttendanceStatus::Izin))
        ->toThrow(AttendanceException::class);

    // API Mandor sync: an in-period item is rejected as period-locked.
    Sanctum::actingAs($mandor);
    $this->postJson('/api/v1/mandor/attendances/sync', ['items' => [[
        'client_id' => (string) Str::uuid(),
        'employee_id' => $other->id,
        'project_id' => $project->id,
        'date' => '2026-07-09',
        'status' => 'hadir',
    ]]])->assertOk()->assertJsonPath('data.0.status', 'rejected')->assertJsonPath('meta.rejected', 1);

    // A DIFFERENT (unpaid) period is still open.
    expect($this->attendance->record($other, $project, '2026-07-13', AttendanceStatus::Hadir))
        ->toBeInstanceOf(Attendance::class);
});

// ---------------------------------------------------------------------------
// SoD — only Finance / Owner / Direktur may pay
// ---------------------------------------------------------------------------

it('authorizes payment to Finance and overseers only, never HR', function () {
    $payroll = Payroll::factory()->create();

    expect(ppRoled('finance')->can('pay', $payroll))->toBeTrue()
        ->and(ppRoled('owner')->can('pay', $payroll))->toBeTrue()
        ->and(ppRoled('direktur')->can('pay', $payroll))->toBeTrue()
        ->and(ppRoled('hr')->can('pay', $payroll))->toBeFalse()          // HR generates, does not pay
        ->and(ppRoled('manager', Bidang::Cufid)->can('pay', $payroll))->toBeFalse()
        ->and(ppRoled('konsumen')->can('pay', $payroll))->toBeFalse();

    // Sanity on SoD split: HR generates, Finance does not.
    expect(ppRoled('hr')->can('generatePayroll', Payroll::class))->toBeTrue()
        ->and(ppRoled('finance')->can('generatePayroll', Payroll::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Cash-book consistency — income and expense now coexist
// ---------------------------------------------------------------------------

it('records the payroll payout on the expense side of the cash book', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '100000.00']);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $payroll = generatedPayroll($this->attendance, $this->payroll, $employee, $project, 6);

    $this->payroll->pay($payroll);

    $expense = Transaction::forPayrolls()->where('reference_id', $payroll->id)->sole();
    expect($expense->type)->toBe(TransactionType::Expense)
        ->and(BigDecimal::of($expense->amount)->isEqualTo('600000.00'))->toBeTrue(); // 6 × 100000
});
