<?php

use App\Enums\Bidang;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\BastRelationManager;
use App\Models\Bast;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\BastService;
use App\Services\CheckoutService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function bastRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

function bastProject(Bidang $bidang = Bidang::Cufid): Project
{
    $project = Project::factory()->inBidang($bidang)->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    return $project->refresh();
}

// ---------------------------------------------------------------------------
// Issue + company signature through the Filament surface
// ---------------------------------------------------------------------------

it('offers Terbitkan BAST to a managing Manager and issues via the service', function () {
    $manager = bastRoled('manager', Bidang::Cufid);
    $project = bastProject(Bidang::Cufid);
    $this->actingAs($manager);

    $rm = Livewire::test(BastRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertTableActionVisible('terbitkanBast');

    $rm->callTableAction('terbitkanBast', data: ['file' => 'bast/demo.pdf']);

    $bast = $project->bast()->first();
    expect($bast)->not->toBeNull()
        ->and($bast->file)->toBe('bast/demo.pdf')
        ->and($bast->status->value)->toBe('draft');
});

it('records the company signature (as the Manager) through the action', function () {
    $manager = bastRoled('manager', Bidang::Cufid);
    $project = bastProject(Bidang::Cufid);
    $bast = app(BastService::class)->issue($project);
    $this->actingAs($manager);

    Livewire::test(BastRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->callTableAction('ttdPerusahaan', record: $bast);

    $bast->refresh();
    expect($bast->signed_company)->toBeTrue()
        ->and((int) $bast->signed_company_by)->toBe($manager->id)
        ->and($bast->isSigned())->toBeFalse(); // customer still pending
});

// ---------------------------------------------------------------------------
// Bidang scoping — a Manager of another bidang cannot manage the BAST
// ---------------------------------------------------------------------------

it('denies BAST management to a Manager of another bidang', function () {
    $project = bastProject(Bidang::Cufid);
    $bast = app(BastService::class)->issue($project);

    $otherManager = bastRoled('manager', Bidang::Cc);

    expect($otherManager->can('issueBast', $project))->toBeFalse()
        ->and($otherManager->can('signCompany', $bast))->toBeFalse();

    // Overseer may manage any bidang.
    expect(bastRoled('direktur')->can('signCompany', $bast))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Bank (Mitra Pembiayaan, L4) — read-only, never signs
// ---------------------------------------------------------------------------

it('gives the financing bank read-only BAST visibility, never signing rights', function () {
    $bank = bastRoled('mitra_pembiayaan');
    $project = bastProject(Bidang::Cufid);
    $project->update(['is_financed' => true, 'bank_mitra_id' => $bank->id]);
    $bast = app(BastService::class)->issue($project);

    // May view the project (and thus its BAST) but cannot sign or issue.
    expect($bank->can('view', $project))->toBeTrue()
        ->and($bank->can('signCompany', $bast))->toBeFalse()
        ->and($bank->can('signCustomer', $bast))->toBeFalse()
        ->and($bank->can('issueBast', $project))->toBeFalse();
});
