<?php

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\PayrollStatus;
use App\Enums\TransactionCategory;
use App\Filament\Resources\PayrollResource;
use App\Filament\Resources\PayrollResource\Pages\ListPayrolls;
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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Payroll week Mon 2026-07-06 .. Sat 2026-07-11.
const PR_START = '2026-07-06';
const PR_END = '2026-07-11';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function prUser(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

/** One daily worker with `$present` attended days in the payroll week. */
function seedAttendance(int $present, string $wage = '150000.00'): Employee
{
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => $wage]);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $att = app(AttendanceService::class);
    foreach (array_slice(['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10', '2026-07-11'], 0, $present) as $d) {
        $att->record($employee, $project, $d, AttendanceStatus::Hadir);
    }

    return $employee;
}

// ---------------------------------------------------------------------------
// RBAC — HR, Finance and overseers see payroll; nobody else
// ---------------------------------------------------------------------------

it('exposes payroll to HR, Finance and overseers only', function () {
    foreach (['hr', 'finance', 'owner', 'direktur'] as $name) {
        $this->actingAs(prUser($name));
        expect(PayrollResource::canViewAny())->toBeTrue("{$name} sees payroll");
    }

    foreach (['manager', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        $this->actingAs(prUser($name, $name === 'manager' || $name === 'mandor' ? Bidang::Cufid : null));
        expect(PayrollResource::canViewAny())->toBeFalse("{$name} must not see payroll");
    }
});

// ---------------------------------------------------------------------------
// HR generates from the UI; the run + payslips appear
// ---------------------------------------------------------------------------

it('lets HR generate a payroll run from the panel', function () {
    seedAttendance(3); // 3 × 150000 = 450000
    $this->actingAs(prUser('hr'));

    Livewire::test(ListPayrolls::class)
        ->assertActionVisible('generate')
        ->callAction('generate', ['period_start' => PR_START, 'period_end' => PR_END])
        ->assertHasNoActionErrors();

    $payroll = Payroll::sole();
    expect($payroll->status)->toBe(PayrollStatus::Draft)
        ->and($payroll->payslips()->count())->toBe(1)
        ->and(BigDecimal::of((string) $payroll->payslips()->sum('net'))->isEqualTo('450000.00'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// SoD — HR generates but cannot pay; Finance pays but cannot generate
// ---------------------------------------------------------------------------

it('hides the generate action from Finance (only HR/overseers generate)', function () {
    $this->actingAs(prUser('finance'));
    Livewire::test(ListPayrolls::class)->assertActionHidden('generate');
});

it('hides the pay action from HR and offers it to Finance (SoD)', function () {
    $payroll = Payroll::factory()->create(['status' => PayrollStatus::Draft]);

    $this->actingAs(prUser('hr'));
    Livewire::test(ListPayrolls::class)->assertTableActionHidden('bayar', $payroll);

    $this->actingAs(prUser('finance'));
    Livewire::test(ListPayrolls::class)->assertTableActionVisible('bayar', $payroll);
});

// ---------------------------------------------------------------------------
// Finance pays from the panel → cash expense posted, run marked paid
// ---------------------------------------------------------------------------

it('lets Finance pay a run from the panel, posting the gaji expense', function () {
    $employee = seedAttendance(4, '100000.00'); // 4 × 100000 = 400000
    app(PayrollService::class)->generate(PR_START, PR_END);
    $payroll = Payroll::sole();

    $this->actingAs(prUser('finance'));
    Livewire::test(ListPayrolls::class)
        ->callTableAction('bayar', $payroll)
        ->assertHasNoTableActionErrors();

    $expense = Transaction::forPayrolls()->where('reference_id', $payroll->id)->sole();
    expect($payroll->fresh()->status)->toBe(PayrollStatus::Paid)
        ->and($expense->category)->toBe(TransactionCategory::Gaji)
        ->and(BigDecimal::of($expense->amount)->isEqualTo('400000.00'))->toBeTrue();
});
