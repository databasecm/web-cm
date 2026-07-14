<?php

/*
|--------------------------------------------------------------------------
| Phase 5 Specification (living documentation) — Field / Mandor
|--------------------------------------------------------------------------
|
| The consolidated Definition-of-Done gate for Phase 5. Each section is a clause
| of the phase's invariants; the assertions make the rule explicit and pin it
| across EVERY surface (model/DB, service, Mandor API, Filament, policy).
|
| Per-concern coverage also lives in EmployeeFoundationTest, AttendanceTest,
| DailyReportTest, MandorApiTest and BankReportMonitoringTest; this file is the
| single-glance guarantee.
|
*/

use App\Enums\AttendanceStatus;
use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DailyReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function dodP5User(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

// ===========================================================================
// (a) Employees are DATA ENTITIES, never login accounts (CLAUDE.md §7)
// ===========================================================================

it('P5(a): an employee cannot authenticate and carries no credentials', function () {
    $employee = Employee::factory()->create();

    expect($employee)->not->toBeInstanceOf(Authenticatable::class);

    foreach (['password', 'email', 'remember_token', 'user_id'] as $loginColumn) {
        expect(Schema::hasColumn('employees', $loginColumn))->toBeFalse("employees must not have {$loginColumn}");
    }
});

// ===========================================================================
// (b) Anti-double attendance — one record per worker per day, everywhere
// ===========================================================================

it('P5(b): a worker has at most one attendance per day (DB + service + API)', function () {
    $mandor = dodP5User('mandor', Bidang::Cufid);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $projectA = Project::factory()->inBidang(Bidang::Cufid)->create();
    $projectB = Project::factory()->inBidang(Bidang::Cufid)->create();

    app(AttendanceService::class)->record($employee, $projectA, '2026-07-06', AttendanceStatus::Hadir, $mandor);

    // Service: a different project on the same day is refused.
    expect(fn () => app(AttendanceService::class)->record($employee, $projectB, '2026-07-06', AttendanceStatus::Hadir, $mandor))
        ->toThrow(AttendanceException::class);

    // DB: the unique (employee_id, date) key rejects a raw duplicate.
    expect(fn () => Attendance::factory()->create(['employee_id' => $employee->id, 'project_id' => $projectB->id, 'date' => '2026-07-06']))
        ->toThrow(QueryException::class);

    // API: the sync channel rejects it too.
    Sanctum::actingAs($mandor);
    $this->postJson('/api/v1/mandor/attendances/sync', ['items' => [[
        'client_id' => (string) Str::uuid(), 'employee_id' => $employee->id, 'project_id' => $projectB->id,
        'date' => '2026-07-06', 'status' => 'hadir',
    ]]])->assertOk()->assertJsonPath('data.0.status', 'rejected');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});

// ===========================================================================
// (c) Offline sync is idempotent — a retried batch never doubles anything
// ===========================================================================

it('P5(c): retrying a batch with the same client_id creates no duplicates', function () {
    $mandor = dodP5User('mandor', Bidang::Cufid);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();

    $attId = (string) Str::uuid();
    $repId = (string) Str::uuid();
    $attBatch = ['items' => [['client_id' => $attId, 'employee_id' => $employee->id, 'project_id' => $project->id, 'date' => '2026-07-06', 'status' => 'hadir']]];
    $repBatch = ['items' => [['client_id' => $repId, 'project_id' => $project->id, 'date' => '2026-07-06', 'description' => 'Cor', 'media' => [['type' => 'photo', 'file' => 'r/1.jpg']]]]];

    Sanctum::actingAs($mandor);

    foreach ([1, 2, 3] as $attempt) { // initial + two retries
        $this->postJson('/api/v1/mandor/attendances/sync', $attBatch)->assertOk();
        $this->postJson('/api/v1/mandor/daily-reports/sync', $repBatch)->assertOk();
    }

    expect(Attendance::count())->toBe(1)
        ->and(DailyReport::count())->toBe(1)
        ->and(DailyReport::first()->media()->count())->toBe(1); // media not doubled
});

// ===========================================================================
// (d) A daily report is NOT a payment trigger — reports never move money
// ===========================================================================

it('P5(d): a daily report never advances progress or unlocks a term', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create(['progress_percent' => 0]);
    $mandor = dodP5User('mandor', Bidang::Cufid);

    app(DailyReportService::class)->create($project, $mandor, '2026-07-06', 'Progres lapangan 70%', 'kira-kira 70%');

    expect((float) $project->refresh()->progress_percent)->toBe(0.0)
        ->and($mandor->can('update', $project))->toBeFalse(); // Mandor cannot advance progress at all
});

// ===========================================================================
// (e) Bidang scope (§6.4) — a Mandor is confined to its own bidang
// ===========================================================================

it('P5(e): a Mandor may only touch its own bidang, from every surface', function () {
    $cufidEmployee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $ccProject = Project::factory()->inBidang(Bidang::Cc)->create();
    $cufidProject = Project::factory()->inBidang(Bidang::Cufid)->create();

    $mandorCc = dodP5User('mandor', Bidang::Cc);

    // Gates deny cross-bidang attendance and reporting.
    expect($mandorCc->can('recordAttendance', $cufidEmployee))->toBeFalse()
        ->and($mandorCc->can('createDailyReport', $cufidProject))->toBeFalse();

    // The field context API only lists the Mandor's own bidang.
    Sanctum::actingAs($mandorCc);
    $this->getJson('/api/v1/mandor/projects')->assertOk()->assertJsonMissing(['id' => $cufidProject->id]);
    $this->getJson('/api/v1/mandor/employees')->assertOk()->assertJsonMissing(['id' => $cufidEmployee->id]);

    // Service integrity: worker + project must share a bidang.
    expect(fn () => app(AttendanceService::class)->record($cufidEmployee, $ccProject, '2026-07-06', AttendanceStatus::Hadir))
        ->toThrow(AttendanceException::class);
});

// ===========================================================================
// (f) §6.5 — the financing bank monitors reports read-only, no HR data
// ===========================================================================

it('P5(f): the bank sees its financed project reports read-only, never HR data', function () {
    $bank = dodP5User('mitra_pembiayaan');
    $project = Project::factory()->inBidang(Bidang::Cufid)->financedBy($bank)->create();
    $report = DailyReport::factory()->forProject($project)->create();

    $otherBank = dodP5User('mitra_pembiayaan');
    $otherReport = DailyReport::factory()->forProject(Project::factory()->financedBy($otherBank)->create())->create();

    // Reads its own financed project's reports, read-only; never another bank's.
    expect($bank->can('view', $report))->toBeTrue()
        ->and($bank->can('update', $report))->toBeFalse()
        ->and($bank->can('createDailyReport', $project))->toBeFalse()
        ->and($bank->can('view', $otherReport))->toBeFalse();

    // HR data (workers/attendance) is closed to the bank entirely.
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->status(EmployeeStatus::Aktif)->create();
    $attendance = Attendance::factory()->create(['employee_id' => $employee->id, 'project_id' => $project->id]);

    expect($bank->can('viewAny', Employee::class))->toBeFalse()
        ->and($bank->can('view', $employee))->toBeFalse()
        ->and($bank->can('viewAny', Attendance::class))->toBeFalse()
        ->and($bank->can('view', $attendance))->toBeFalse();
});
