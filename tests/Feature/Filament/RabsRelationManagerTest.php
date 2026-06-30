<?php

use App\Enums\Bidang;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\ProjectResource\RelationManagers\RabsRelationManager;
use App\Models\Ahsap;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function rabManager(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

// NOTE: the build math, AHSAP snapshot freeze and rate snapshot are gated by the
// service-level RabBuilderTest. Here we cover the relation-manager surface:
// the "Buat RAB" action is offered to managing staff, and its AHSAP picker is
// confined to the project's bidang.

it('offers the Buat RAB action to a managing Manager', function () {
    $manager = rabManager(Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)->create();
    $this->actingAs($manager);

    Livewire::test(RabsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertTableActionVisible('buatRab');

    expect($manager->can('create', Rab::class))->toBeTrue();
});

it('only offers AHSAP from the project bidang in the builder', function () {
    $manager = rabManager(Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)->create();

    $cufidAhsap = Ahsap::factory()->inBidang(Bidang::Cufid)->create(['name' => 'Pekerjaan Cufid']);
    $ccAhsap = Ahsap::factory()->inBidang(Bidang::Cc)->create(['name' => 'Pekerjaan CC']);

    $this->actingAs($manager);

    $rm = Livewire::test(RabsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->instance();

    $options = (fn () => $this->ahsapOptions())->call($rm);

    expect($options)->toHaveKey($cufidAhsap->id)
        ->and($options)->not->toHaveKey($ccAhsap->id);
});

it('lists built RAB versions newest first', function () {
    $manager = rabManager(Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)->create();
    $v1 = Rab::factory()->for($project)->create(['version' => 1]);
    $v2 = Rab::factory()->for($project)->create(['version' => 2]);
    $this->actingAs($manager);

    Livewire::test(RabsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertCanSeeTableRecords([$v2, $v1]);
});
