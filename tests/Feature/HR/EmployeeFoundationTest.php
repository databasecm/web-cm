<?php

use App\Enums\Bidang;
use App\Enums\EmployeeStatusChangeType;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->employees = app(EmployeeService::class);
});

function empRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// HARD RULE: an employee is a data entity, NOT a login account (§7)
// ---------------------------------------------------------------------------

it('is a data entity with no login capability', function () {
    $employee = Employee::factory()->create();

    // Not authenticatable, and the table carries no credential/identity columns.
    expect($employee)->not->toBeInstanceOf(Authenticatable::class);

    foreach (['password', 'email', 'remember_token', 'user_id'] as $loginColumn) {
        expect(Schema::hasColumn('employees', $loginColumn))->toBeFalse("employees must not have a {$loginColumn} column");
    }

    // `managed_by` is the managing Mandor (attribution), never the employee's identity.
    $mandor = empRoled('mandor', Bidang::Cufid);
    $managed = Employee::factory()->managedBy($mandor)->create();
    expect((int) $managed->managed_by)->toBe($mandor->id)
        ->and($managed->manager->is($mandor))->toBeTrue();
});

// ---------------------------------------------------------------------------
// RBAC — Mandor bidang-scoped, HR full, Manager view-only, others none
// ---------------------------------------------------------------------------

it('scopes management to the Mandor bidang and gives HR full access', function () {
    $cufid = Employee::factory()->inBidang(Bidang::Cufid)->create();

    $mandorCufid = empRoled('mandor', Bidang::Cufid);
    $mandorCc = empRoled('mandor', Bidang::Cc);
    $hr = empRoled('hr');
    $manager = empRoled('manager', Bidang::Cufid);

    // Mandor manages only its bidang.
    expect($mandorCufid->can('view', $cufid))->toBeTrue()
        ->and($mandorCufid->can('update', $cufid))->toBeTrue()
        ->and($mandorCufid->can('create', Employee::class))->toBeTrue()
        ->and($mandorCc->can('view', $cufid))->toBeFalse()
        ->and($mandorCc->can('update', $cufid))->toBeFalse();

    // HR + overseers: full access.
    expect($hr->can('update', $cufid))->toBeTrue()
        ->and(empRoled('direktur')->can('update', $cufid))->toBeTrue();

    // Manager: view its bidang only, never manages.
    expect($manager->can('view', $cufid))->toBeTrue()
        ->and($manager->can('update', $cufid))->toBeFalse()
        ->and($manager->can('create', Employee::class))->toBeFalse();
});

it('gives Mitra and Konsumen no access to employees', function () {
    $employee = Employee::factory()->create();

    foreach (['mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $actor = empRoled($name);
        expect($actor->can('viewAny', Employee::class))->toBeFalse("{$name} must not list employees")
            ->and($actor->can('view', $employee))->toBeFalse()
            ->and($actor->can('create', Employee::class))->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// Status log — position/wage changes are recorded (paper trail)
// ---------------------------------------------------------------------------

it('logs a promotion and a wage change', function () {
    $by = empRoled('hr');
    $employee = Employee::factory()->create(['position' => 'Tukang', 'daily_wage' => '150000.00']);

    $this->employees->changePosition($employee, 'Kepala Tukang', $by);
    $this->employees->changeWage($employee, '175000', $by);

    $employee->refresh();
    expect($employee->position)->toBe('Kepala Tukang')
        ->and($employee->daily_wage)->toBe('175000.00')
        ->and($employee->statusLogs()->count())->toBe(2);

    $promotion = $employee->statusLogs()->where('change_type', EmployeeStatusChangeType::Promotion->value)->first();
    expect($promotion->old_value)->toBe('Tukang')
        ->and($promotion->new_value)->toBe('Kepala Tukang')
        ->and((int) $promotion->created_by)->toBe($by->id);

    $salary = $employee->statusLogs()->where('change_type', EmployeeStatusChangeType::Salary->value)->first();
    expect($salary->old_value)->toBe('150000.00')
        ->and($salary->new_value)->toBe('175000.00');
});
