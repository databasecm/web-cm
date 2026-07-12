<?php

use App\Enums\FinancingStatus;
use App\Filament\Resources\FinancedProjectResource;
use App\Filament\Resources\FinancedProjectResource\Pages\ListFinancedProjects;
use App\Models\Financing;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancingService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function monBank(string $role = 'mitra_pembiayaan'): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
}

function monKons(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
}

// ---------------------------------------------------------------------------
// The dashboard surfaces the bank's own financing status/amount (read-only)
// ---------------------------------------------------------------------------

it('shows the financing status and amount on the financed-project view', function () {
    $bank = monBank();
    $konsumen = monKons();
    $project = Project::factory()->financedBy($bank)->ownedBy($konsumen)->create(['title' => 'Renovasi Dapur']);

    $financing = Financing::factory()->forProject($project)->forBank($bank)
        ->status(FinancingStatus::Approved)->create(['amount' => '90000000.00']);
    app(FinancingService::class)->disburse($financing); // → disbursed + income + log

    $this->actingAs($bank);

    expect($financing->fresh()->status)->toBe(FinancingStatus::Disbursed);

    $this->get(FinancedProjectResource::getUrl('view', ['record' => $project]))
        ->assertOk()
        ->assertSee('Pembiayaan')                             // financing section
        ->assertSee(FinancingStatus::Disbursed->label())      // status surfaced
        ->assertSee('Renovasi Dapur');                        // project context

    // The financing column shows up on the list too.
    Livewire::test(ListFinancedProjects::class)
        ->assertCanSeeTableRecords([$project])
        ->assertSee(FinancingStatus::Disbursed->label());
});

it('shows nothing of another bank financing on the dashboard', function () {
    $bank = monBank();
    $otherBank = monBank();
    $project = Project::factory()->financedBy($otherBank)->create();
    Financing::factory()->forProject($project)->forBank($otherBank)->create();

    $this->actingAs($bank);

    // Another bank's financed project is not reachable at all (§6.5, 404).
    $this->get(FinancedProjectResource::getUrl('view', ['record' => $project]))->assertNotFound();
});

it('denies the dashboard to a Supplier (also L4)', function () {
    $this->actingAs(monBank('supplier'));
    expect(FinancedProjectResource::canViewAny())->toBeFalse();
});
