<?php

use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Models\Attendance;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function mandorUser(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'mandor')->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// Channel guard
// ---------------------------------------------------------------------------

it('restricts the field channel to Mandor accounts', function () {
    $this->getJson('/api/v1/mandor/projects')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['role_id' => Role::where('name', 'konsumen')->value('id')]));
    $this->getJson('/api/v1/mandor/projects')->assertForbidden();
});

// ---------------------------------------------------------------------------
// Context — bidang-scoped projects & employees
// ---------------------------------------------------------------------------

it('lists only the projects and active workers of the Mandor bidang', function () {
    $mandor = mandorUser(Bidang::Cufid);
    $mine = Project::factory()->inBidang(Bidang::Cufid)->create();
    Project::factory()->inBidang(Bidang::Cc)->create(); // other bidang
    $worker = Employee::factory()->inBidang(Bidang::Cufid)->create();
    Employee::factory()->inBidang(Bidang::Cufid)->status(EmployeeStatus::Nonaktif)->create(); // inactive
    Employee::factory()->inBidang(Bidang::Cc)->create(); // other bidang

    Sanctum::actingAs($mandor);

    $this->getJson('/api/v1/mandor/projects')->assertOk()->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.id', $mine->id);
    $this->getJson('/api/v1/mandor/employees')->assertOk()->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.id', $worker->id);
});

// ---------------------------------------------------------------------------
// Idempotent attendance sync — retry never double-records
// ---------------------------------------------------------------------------

it('syncs an attendance batch idempotently on retry', function () {
    $mandor = mandorUser(Bidang::Cufid);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $clientId = (string) Str::uuid();

    $payload = ['items' => [[
        'client_id' => $clientId,
        'employee_id' => $employee->id,
        'project_id' => $project->id,
        'date' => '2026-07-06',
        'status' => 'hadir',
    ]]];

    Sanctum::actingAs($mandor);

    $this->postJson('/api/v1/mandor/attendances/sync', $payload)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'created')
        ->assertJsonPath('meta.created', 1);

    // Retry with the SAME client_id → duplicate, no new row.
    $this->postJson('/api/v1/mandor/attendances/sync', $payload)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'duplicate')
        ->assertJsonPath('meta.duplicate', 1);

    expect(Attendance::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Guards hold through the API: anti-double, bidang, inactive; partial batch
// ---------------------------------------------------------------------------

it('processes valid items and rejects invalid ones in a partial batch', function () {
    $mandor = mandorUser(Bidang::Cufid);
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $inactive = Employee::factory()->inBidang(Bidang::Cufid)->status(EmployeeStatus::Nonaktif)->create();
    $otherEmployee = Employee::factory()->inBidang(Bidang::Cc)->create();
    $otherProject = Project::factory()->inBidang(Bidang::Cc)->create();

    // Pre-existing attendance for the same (employee, date) to trigger anti-double.
    Attendance::factory()->create(['employee_id' => $employee->id, 'project_id' => $project->id, 'date' => '2026-07-06']);

    $valid = Employee::factory()->inBidang(Bidang::Cufid)->create();

    Sanctum::actingAs($mandor);

    $response = $this->postJson('/api/v1/mandor/attendances/sync', ['items' => [
        ['client_id' => (string) Str::uuid(), 'employee_id' => $valid->id, 'project_id' => $project->id, 'date' => '2026-07-06', 'status' => 'hadir'],           // created
        ['client_id' => (string) Str::uuid(), 'employee_id' => $employee->id, 'project_id' => $project->id, 'date' => '2026-07-06', 'status' => 'hadir'],        // anti-double reject
        ['client_id' => (string) Str::uuid(), 'employee_id' => $inactive->id, 'project_id' => $project->id, 'date' => '2026-07-07', 'status' => 'hadir'],        // inactive reject
        ['client_id' => (string) Str::uuid(), 'employee_id' => $otherEmployee->id, 'project_id' => $otherProject->id, 'date' => '2026-07-06', 'status' => 'hadir'], // out-of-bidang reject
    ]]);

    $response->assertOk()
        ->assertJsonPath('meta.created', 1)
        ->assertJsonPath('meta.rejected', 3)
        ->assertJsonPath('data.0.status', 'created')
        ->assertJsonPath('data.1.status', 'rejected')
        ->assertJsonPath('data.2.status', 'rejected')
        ->assertJsonPath('data.3.status', 'rejected');

    // Only the one valid new attendance was added (plus the pre-existing seed).
    expect(Attendance::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Daily report sync — idempotent, media attached once, progress untouched
// ---------------------------------------------------------------------------

it('syncs a daily report batch idempotently with media', function () {
    $mandor = mandorUser(Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create(['progress_percent' => 0]);
    $clientId = (string) Str::uuid();

    $payload = ['items' => [[
        'client_id' => $clientId,
        'project_id' => $project->id,
        'date' => '2026-07-06',
        'description' => 'Cor lantai 1',
        'progress_note' => 'sekitar 60% menurut mandor',
        'media' => [['type' => 'photo', 'file' => 'reports/cor.jpg', 'caption' => 'progres']],
    ]]];

    Sanctum::actingAs($mandor);

    $this->postJson('/api/v1/mandor/daily-reports/sync', $payload)->assertOk()
        ->assertJsonPath('data.0.status', 'created');
    $this->postJson('/api/v1/mandor/daily-reports/sync', $payload)->assertOk()
        ->assertJsonPath('data.0.status', 'duplicate'); // retry

    expect(DailyReport::count())->toBe(1)
        ->and(DailyReport::first()->media()->count())->toBe(1) // media not doubled on retry
        ->and((float) $project->refresh()->progress_percent)->toBe(0.0); // progress untouched
});

// ---------------------------------------------------------------------------
// Recap — a Mandor sees only its bidang; day filter
// ---------------------------------------------------------------------------

it('returns the day recap scoped to the Mandor bidang', function () {
    $mandor = mandorUser(Bidang::Cufid);
    $cufidEmp = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $ccEmp = Employee::factory()->inBidang(Bidang::Cc)->create();
    Attendance::factory()->create(['employee_id' => $cufidEmp->id, 'project_id' => Project::factory()->inBidang(Bidang::Cufid)->create()->id, 'date' => '2026-07-06']);
    Attendance::factory()->create(['employee_id' => $ccEmp->id, 'project_id' => Project::factory()->inBidang(Bidang::Cc)->create()->id, 'date' => '2026-07-06']);

    Sanctum::actingAs($mandor);

    $this->getJson('/api/v1/mandor/attendances?date=2026-07-06')
        ->assertOk()
        ->assertJsonPath('meta.count', 1); // only CuFID
});
