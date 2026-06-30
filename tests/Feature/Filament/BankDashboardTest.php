<?php

use App\Filament\Resources\AhsapResource;
use App\Filament\Resources\ConsultationResource;
use App\Filament\Resources\FinancedProjectResource;
use App\Filament\Resources\FinancedProjectResource\Pages\ListFinancedProjects;
use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\SupplierResource;
use App\Filament\Resources\UserResource;
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

function mitra(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'mitra_pembiayaan')->value('id')]);
}

// ---------------------------------------------------------------------------
// Visibility — only a financing Mitra owns this dashboard
// ---------------------------------------------------------------------------

it('shows the financing dashboard only to a Mitra Pembiayaan', function () {
    $this->actingAs(mitra());
    expect(FinancedProjectResource::canViewAny())->toBeTrue();

    foreach (['owner', 'direktur', 'manager', 'finance', 'hr', 'supplier', 'mandor', 'konsumen'] as $name) {
        $this->actingAs(User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]));
        expect(FinancedProjectResource::canViewAny())->toBeFalse("{$name} must not see the financing dashboard");
    }
});

// ---------------------------------------------------------------------------
// Scope — own financed projects only, other banks' invisible (§6.5)
// ---------------------------------------------------------------------------

it('lists only the projects the Mitra finances', function () {
    $me = mitra();
    $other = mitra();

    $mine = Project::factory()->financedBy($me)->create();
    $theirs = Project::factory()->financedBy($other)->create();
    $unfinanced = Project::factory()->create(); // no bank_mitra_id

    $this->actingAs($me);

    Livewire::test(ListFinancedProjects::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs, $unfinanced]);
});

it('returns 404 when a Mitra opens another bank project', function () {
    $me = mitra();
    $other = mitra();

    $mine = Project::factory()->financedBy($me)->create();
    $theirs = Project::factory()->financedBy($other)->create();

    $this->actingAs($me);

    $this->get(FinancedProjectResource::getUrl('view', ['record' => $mine]))->assertOk();
    $this->get(FinancedProjectResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Read-only — every mutation gate is closed
// ---------------------------------------------------------------------------

it('forbids every mutation on the financing dashboard', function () {
    $me = mitra();
    $project = Project::factory()->financedBy($me)->create();

    $this->actingAs($me);

    expect(FinancedProjectResource::canCreate())->toBeFalse()
        ->and(FinancedProjectResource::canEdit($project))->toBeFalse()
        ->and(FinancedProjectResource::canDelete($project))->toBeFalse()
        ->and(FinancedProjectResource::canDeleteAny())->toBeFalse();

    // No create/edit pages are registered at all — only index + view.
    expect(array_keys(FinancedProjectResource::getPages()))->toBe(['index', 'view']);
});

// ---------------------------------------------------------------------------
// Isolation — internal resources stay hidden from the Mitra
// ---------------------------------------------------------------------------

it('hides every internal resource from a Mitra', function () {
    $this->actingAs(mitra());

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(AhsapResource::canViewAny())->toBeFalse()
        ->and(MaterialResource::canViewAny())->toBeFalse()
        ->and(SupplierResource::canViewAny())->toBeFalse()
        ->and(ConsultationResource::canViewAny())->toBeFalse()
        ->and(ProjectResource::canViewAny())->toBeFalse();
});
