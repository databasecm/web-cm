<?php

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->attendance = app(AttendanceService::class);
});

function attRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

function attProject(Bidang $bidang): Project
{
    return Project::factory()->inBidang($bidang)->create();
}

// ---------------------------------------------------------------------------
// Anti-double: one attendance per worker per day (payroll guard)
// ---------------------------------------------------------------------------

it('refuses a second attendance for the same worker and day, even on another project', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $projectA = attProject(Bidang::Cufid);
    $projectB = attProject(Bidang::Cufid);
    $mandor = attRoled('mandor', Bidang::Cufid);

    $this->attendance->record($employee, $projectA, '2026-07-06', AttendanceStatus::Hadir, $mandor);

    // Service: a different project on the same day is still refused.
    expect(fn () => $this->attendance->record($employee, $projectB, '2026-07-06', AttendanceStatus::Hadir, $mandor))
        ->toThrow(AttendanceException::class);

    // DB: the unique (employee_id, date) key rejects a raw duplicate too.
    expect(fn () => Attendance::factory()->create([
        'employee_id' => $employee->id,
        'project_id' => $projectB->id,
        'date' => '2026-07-06',
    ]))->toThrow(QueryException::class);

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Bidang scope — cross-bidang attendance is blocked (service + policy)
// ---------------------------------------------------------------------------

it('blocks cross-bidang attendance in the service and the gate', function () {
    $cufidEmployee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $ccProject = attProject(Bidang::Cc);
    $cufidProject = attProject(Bidang::Cufid);

    // Service integrity: worker + project must share a bidang.
    expect(fn () => $this->attendance->record($cufidEmployee, $ccProject, '2026-07-06', AttendanceStatus::Hadir))
        ->toThrow(AttendanceException::class);

    // Gate: a CC Mandor cannot record a CuFID worker; a CuFID Mandor can.
    expect(attRoled('mandor', Bidang::Cc)->can('recordAttendance', $cufidEmployee))->toBeFalse()
        ->and(attRoled('mandor', Bidang::Cufid)->can('recordAttendance', $cufidEmployee))->toBeTrue();

    // Sanity: same-bidang record succeeds.
    $this->attendance->record($cufidEmployee, $cufidProject, '2026-07-06', AttendanceStatus::Hadir);
    expect(Attendance::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Inactive worker cannot be attended
// ---------------------------------------------------------------------------

it('refuses to attend an inactive worker', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->status(EmployeeStatus::Nonaktif)->create();
    $project = attProject(Bidang::Cufid);

    expect(fn () => $this->attendance->record($employee, $project, '2026-07-06', AttendanceStatus::Hadir))
        ->toThrow(AttendanceException::class);
    expect(Attendance::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Recap — attended-day count is the payroll base
// ---------------------------------------------------------------------------

it('counts attended days in a range accurately', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $project = attProject(Bidang::Cufid);

    $this->attendance->record($employee, $project, '2026-07-06', AttendanceStatus::Hadir);
    $this->attendance->record($employee, $project, '2026-07-07', AttendanceStatus::Hadir);
    $this->attendance->record($employee, $project, '2026-07-08', AttendanceStatus::Izin);
    $this->attendance->record($employee, $project, '2026-07-09', AttendanceStatus::Alpa);
    $this->attendance->record($employee, $project, '2026-07-13', AttendanceStatus::Hadir); // outside range

    // Mon–Sat of the payroll week: only 'hadir' counts.
    expect($this->attendance->countHadir($employee, '2026-07-06', '2026-07-11'))->toBe(2);
});

// ---------------------------------------------------------------------------
// Correction — a mis-entry can be fixed (audited)
// ---------------------------------------------------------------------------

it('lets the recorder correct a day status', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $project = attProject(Bidang::Cufid);
    $mandor = attRoled('mandor', Bidang::Cufid);

    $att = $this->attendance->record($employee, $project, '2026-07-06', AttendanceStatus::Alpa, $mandor);
    $this->attendance->correct($att, AttendanceStatus::Hadir, $mandor, 'salah input');

    expect($att->refresh()->status)->toBe(AttendanceStatus::Hadir)
        ->and($att->note)->toBe('salah input')
        ->and($mandor->can('update', $att))->toBeTrue();
});

// ---------------------------------------------------------------------------
// RBAC — Manager view-only; Mitra/Konsumen no access
// ---------------------------------------------------------------------------

it('gives Manager view-only and denies Mitra/Konsumen', function () {
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $att = Attendance::factory()->create(['employee_id' => $employee->id, 'project_id' => attProject(Bidang::Cufid)->id]);

    $manager = attRoled('manager', Bidang::Cufid);
    expect($manager->can('view', $att))->toBeTrue()
        ->and($manager->can('recordAttendance', $employee))->toBeFalse()
        ->and($manager->can('update', $att))->toBeFalse();

    foreach (['mitra_pembiayaan', 'konsumen'] as $name) {
        $actor = attRoled($name);
        expect($actor->can('viewAny', Attendance::class))->toBeFalse()
            ->and($actor->can('view', $att))->toBeFalse()
            ->and($actor->can('recordAttendance', $employee))->toBeFalse();
    }
});
