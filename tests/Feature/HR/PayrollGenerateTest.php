<?php

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\PayrollService;
use Brick\Math\BigDecimal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The payroll week: Monday 2026-07-06 .. Saturday 2026-07-11 (payday).
const P_START = '2026-07-06';
const P_END = '2026-07-11';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->payroll = app(PayrollService::class);
    $this->attendance = app(AttendanceService::class);
});

function hrRoled(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => Bidang::Cufid]);
}

/** Record `hadir` on the given period dates, plus one izin + one alpa (not counted). */
function attendWeek(AttendanceService $service, Employee $employee, Project $project, int $presentDays): void
{
    $dates = ['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10', '2026-07-11'];
    foreach (array_slice($dates, 0, $presentDays) as $d) {
        $service->record($employee, $project, $d, AttendanceStatus::Hadir);
    }
}

// ---------------------------------------------------------------------------
// Generation — days present accurate, gross exact (BigDecimal)
// ---------------------------------------------------------------------------

it('generates a payslip with attended days and exact gross', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();

    // 4 hadir + 1 izin + 1 alpa in the week → only 4 count.
    attendWeek($this->attendance, $employee, $project, 4);
    $this->attendance->record($employee, $project, '2026-07-10', AttendanceStatus::Izin);
    $this->attendance->record($employee, $project, '2026-07-11', AttendanceStatus::Alpa);

    $payroll = $this->payroll->generate(P_START, P_END);
    $slip = $payroll->payslips()->where('employee_id', $employee->id)->sole();

    expect($payroll->status->value)->toBe('draft')
        ->and($slip->days_present)->toBe(4)
        ->and(BigDecimal::of($slip->gross)->isEqualTo('600000.00'))->toBeTrue() // 4 × 150000
        ->and(BigDecimal::of($slip->net)->isEqualTo('600000.00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Only active daily workers are on the daily run
// ---------------------------------------------------------------------------

it('excludes inactive and monthly workers from the daily payroll', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();

    $daily = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $inactive = Employee::factory()->inBidang(Bidang::Cufid)->status(EmployeeStatus::Nonaktif)->create();
    $monthly = Employee::factory()->inBidang(Bidang::Cufid)->type(EmployeeType::Bulanan)->create();

    // All three have attendance in the period, but only the active daily one counts.
    // (Inactive can't be attended via the service, so seed its attendance directly.)
    attendWeek($this->attendance, $daily, $project, 6);
    Attendance::factory()->create(['employee_id' => $inactive->id, 'project_id' => $project->id, 'date' => P_START]);
    attendWeek($this->attendance, $monthly, $project, 6);

    $payroll = $this->payroll->generate(P_START, P_END);

    expect($payroll->payslips()->count())->toBe(1)
        ->and((int) $payroll->payslips()->sole()->employee_id)->toBe($daily->id);
});

// ---------------------------------------------------------------------------
// Idempotent — regenerating the same period never doubles
// ---------------------------------------------------------------------------

it('is idempotent: regenerating a draft period does not duplicate', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    attendWeek($this->attendance, $employee, $project, 3);

    $first = $this->payroll->generate(P_START, P_END);
    $firstId = $first->id;

    // Another attended day, then regenerate → same payroll, refreshed payslip.
    $this->attendance->record($employee, $project, '2026-07-09', AttendanceStatus::Hadir);
    $second = $this->payroll->generate(P_START, P_END);

    expect($second->id)->toBe($firstId)
        ->and(Payroll::count())->toBe(1)
        ->and(Payslip::where('employee_id', $employee->id)->count())->toBe(1)
        ->and($second->payslips()->sole()->days_present)->toBe(4); // picked up the new day
});

// ---------------------------------------------------------------------------
// RBAC — HR/overseers generate; Finance and others cannot
// ---------------------------------------------------------------------------

it('authorizes payroll generation to HR and overseers only', function () {
    foreach (['hr', 'owner', 'direktur'] as $name) {
        expect(hrRoled($name)->can('generatePayroll', Payroll::class))->toBeTrue("{$name} should generate");
    }

    foreach (['finance', 'manager', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        expect(hrRoled($name)->can('generatePayroll', Payroll::class))->toBeFalse("{$name} must not generate");
    }
});
