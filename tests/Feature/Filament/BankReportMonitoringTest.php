<?php

use App\Enums\Bidang;
use App\Filament\Resources\FinancedProjectResource;
use App\Filament\Resources\FinancedProjectResource\Pages\ViewFinancedProject;
use App\Filament\Resources\FinancedProjectResource\RelationManagers\DailyReportsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\BastRelationManager;
use App\Models\Attendance;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\Project;
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

function repBank(string $role = 'mitra_pembiayaan'): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
}

// ---------------------------------------------------------------------------
// Bank sees the daily reports of its own financed project (read-only)
// ---------------------------------------------------------------------------

it('shows the daily reports of the financed project to the bank, read-only', function () {
    $bank = repBank();
    $project = Project::factory()->inBidang(Bidang::Cufid)->financedBy($bank)->create();
    $report = DailyReport::factory()->forProject($project)->create(['description' => 'Cor lantai 1']);

    $this->actingAs($bank);

    Livewire::test(DailyReportsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewFinancedProject::class,
    ])->assertCanSeeTableRecords([$report])
        ->assertSee('Cor lantai 1');

    // Read-only: the relation manager exposes no create/edit/delete.
    $rm = new DailyReportsRelationManager;
    expect($rm->isReadOnly())->toBeTrue();
});

// ---------------------------------------------------------------------------
// §6.5 — the bank cannot write/edit a daily report
// ---------------------------------------------------------------------------

it('never lets the bank write or edit a daily report', function () {
    $bank = repBank();
    $project = Project::factory()->inBidang(Bidang::Cufid)->financedBy($bank)->create();
    $report = DailyReport::factory()->forProject($project)->create();

    expect($bank->can('view', $report))->toBeTrue()          // read-only monitoring
        ->and($bank->can('update', $report))->toBeFalse()    // cannot edit
        ->and($bank->can('createDailyReport', $project))->toBeFalse(); // cannot write
});

// ---------------------------------------------------------------------------
// Another bank's project is invisible; Supplier gets nothing
// ---------------------------------------------------------------------------

it('hides another bank project and denies a Supplier', function () {
    $bank = repBank();
    $otherBank = repBank();
    $theirs = Project::factory()->financedBy($otherBank)->create();
    DailyReport::factory()->forProject($theirs)->create();

    $this->actingAs($bank);
    $this->get(FinancedProjectResource::getUrl('view', ['record' => $theirs]))->assertNotFound();

    $this->actingAs(repBank('supplier'));
    expect(FinancedProjectResource::canViewAny())->toBeFalse();
});

// ---------------------------------------------------------------------------
// HR data (attendance/wages) is NOT exposed to the bank
// ---------------------------------------------------------------------------

it('does not expose worker attendance or wages to the bank', function () {
    $bank = repBank();

    // The dashboard only relates installments + daily reports — no HR surface.
    $relations = FinancedProjectResource::getRelations();
    expect($relations)->toContain(DailyReportsRelationManager::class)
        ->and($relations)->not->toContain(BastRelationManager::class);

    // The bank has zero access to the employee / attendance data itself.
    $employee = Employee::factory()->inBidang(Bidang::Cufid)->create();
    $attendance = Attendance::factory()->create([
        'employee_id' => $employee->id,
        'project_id' => Project::factory()->inBidang(Bidang::Cufid)->create()->id,
    ]);

    expect($bank->can('viewAny', Employee::class))->toBeFalse()
        ->and($bank->can('view', $employee))->toBeFalse()
        ->and($bank->can('viewAny', Attendance::class))->toBeFalse()
        ->and($bank->can('view', $attendance))->toBeFalse();
});
