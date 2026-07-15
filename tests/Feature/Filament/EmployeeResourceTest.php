<?php

use App\Enums\Bidang;
use App\Enums\EmployeeStatusChangeType;
use App\Enums\EmployeeType;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function empUser(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// RBAC — who reaches the employee master
// ---------------------------------------------------------------------------

it('exposes the employee master to HR, overseers, Manager and Mandor only', function () {
    foreach (['hr', 'owner', 'direktur'] as $name) {
        $this->actingAs(empUser($name));
        expect(EmployeeResource::canViewAny())->toBeTrue("{$name} sees employees");
    }
    $this->actingAs(empUser('manager', Bidang::Cufid));
    expect(EmployeeResource::canViewAny())->toBeTrue('manager sees employees');
    $this->actingAs(empUser('mandor', Bidang::Cufid));
    expect(EmployeeResource::canViewAny())->toBeTrue('mandor sees employees');

    // Finance manages money, never workers; Mitra/Konsumen nothing.
    foreach (['finance', 'mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $this->actingAs(empUser($name));
        expect(EmployeeResource::canViewAny())->toBeFalse("{$name} must not see employees");
    }
});

// ---------------------------------------------------------------------------
// HR creates a worker; wage changes only through the logged action
// ---------------------------------------------------------------------------

it('lets HR create a worker', function () {
    $this->actingAs(empUser('hr'));

    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'name' => 'Budi',
            'bidang' => Bidang::Cufid->value,
            'type' => EmployeeType::Harian->value,
            'position' => 'Tukang',
            'daily_wage' => '150000',
            'status' => 'aktif',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Employee::where('name', 'Budi')->exists())->toBeTrue();
});

it('changes the daily wage only through the logged action, writing a status log', function () {
    $hr = empUser('hr');
    $this->actingAs($hr);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create(['daily_wage' => '150000.00']);

    Livewire::test(ListEmployees::class)
        ->callTableAction('ubahGaji', $employee, data: ['daily_wage' => '200000', 'effective_date' => '2026-07-10'])
        ->assertHasNoTableActionErrors();

    expect((float) $employee->fresh()->daily_wage)->toBe(200000.0);

    $log = $employee->statusLogs()->where('change_type', EmployeeStatusChangeType::Salary)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->old_value)->toBe('150000.00')
        ->and($log->new_value)->toBe('200000.00')
        ->and((int) $log->created_by)->toBe($hr->id);
});

// ---------------------------------------------------------------------------
// Bidang scoping — Manager/Mandor never see other units' workers
// ---------------------------------------------------------------------------

it('scopes the list to a bidang-scoped actor and hides other bidang workers', function () {
    $mine = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $theirs = Employee::factory()->inBidang(Bidang::Cc)->create();

    $this->actingAs(empUser('manager', Bidang::Cufid));

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('pins a Mandor-created worker to the Mandor bidang and attributes it', function () {
    $mandor = empUser('mandor', Bidang::Cufid);
    $this->actingAs($mandor);

    Livewire::test(CreateEmployee::class)
        ->fillForm([
            'name' => 'Asep',
            'type' => EmployeeType::Harian->value,
            'daily_wage' => '140000',
            'status' => 'aktif',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::where('name', 'Asep')->sole();
    expect($employee->bidang)->toBe(Bidang::Cufid)
        ->and((int) $employee->managed_by)->toBe($mandor->id);
});

// ---------------------------------------------------------------------------
// A Manager is read-only — cannot create or run the wage/position actions
// ---------------------------------------------------------------------------

it('denies a Manager any employee mutation', function () {
    $manager = empUser('manager', Bidang::Cufid);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();

    expect($manager->can('create', Employee::class))->toBeFalse()
        ->and($manager->can('update', $employee))->toBeFalse()
        ->and($manager->can('delete', $employee))->toBeFalse();

    $this->actingAs($manager);
    Livewire::test(ListEmployees::class)
        ->assertTableActionHidden('ubahGaji', $employee)
        ->assertTableActionHidden('ubahJabatan', $employee);
});
