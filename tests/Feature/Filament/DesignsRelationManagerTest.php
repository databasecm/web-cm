<?php

use App\Enums\Bidang;
use App\Enums\DesignStatus;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\DesignsRelationManager;
use App\Models\Design;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
    Storage::fake('media');
});

function pUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

it('restricts the project resource to managing staff', function () {
    foreach (['owner', 'direktur', 'manager'] as $name) {
        $this->actingAs(pUser($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(ProjectResource::canViewAny())->toBeTrue("{$name} should see projects");
    }

    foreach (['finance', 'hr', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        $this->actingAs(pUser($name, $name === 'mandor' ? Bidang::Cufid : null));
        expect(ProjectResource::canViewAny())->toBeFalse("{$name} must not see the project resource");
    }
});

it('lets a Manager add a design version and submit it', function () {
    $manager = pUser('manager', Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)->create();
    $this->actingAs($manager);

    Livewire::test(DesignsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableAction('tambahVersi', data: [
            'upload' => UploadedFile::fake()->image('desain-v1.png'),
            'notes' => 'Revisi awal',
        ])
        ->assertHasNoTableActionErrors();

    $design = $project->designs()->sole();
    expect($design->version)->toBe(1)
        ->and($design->status)->toBe(DesignStatus::Draft)
        // The binary was stored through MediaService, not a path string.
        ->and($design->file)->toStartWith('designs/')
        ->and(Storage::disk('media')->exists($design->file))->toBeTrue();

    // Submit it to the consumer.
    Livewire::test(DesignsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->callTableAction('ajukan', $design);

    expect($design->refresh()->status)->toBe(DesignStatus::Submitted);
});

it('does not offer submit on an already-submitted design', function () {
    $manager = pUser('manager', Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)->create();
    $submitted = Design::factory()->for($project)->submitted()->create();
    $this->actingAs($manager);

    Livewire::test(DesignsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertTableActionHidden('ajukan', $submitted);
});
