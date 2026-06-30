<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ConsultationResource\Pages\ViewConsultation;
use App\Models\Consultation;
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

function bridgeManager(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

function bridgeKonsumen(): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
    ]);
}

it('offers Buat Proyek only on a deal with an account and no existing project', function () {
    $manager = bridgeManager(Bidang::Cufid);
    $this->actingAs($manager);

    $deal = Consultation::factory()->ownedBy(bridgeKonsumen())->claimedBy($manager)
        ->inBidang(Bidang::Cufid)->status(ConsultationStatus::Deal)->create();
    Livewire::test(ViewConsultation::class, ['record' => $deal->getKey()])
        ->assertActionVisible('buatProyek');

    // Open (not a deal) → hidden.
    $open = Consultation::factory()->ownedBy(bridgeKonsumen())->claimedBy($manager)
        ->inBidang(Bidang::Cufid)->status(ConsultationStatus::Open)->create();
    Livewire::test(ViewConsultation::class, ['record' => $open->getKey()])
        ->assertActionHidden('buatProyek');

    // Deal already converted → hidden.
    Project::factory()->create(['consultation_id' => $deal->id, 'konsumen_id' => $deal->konsumen_id]);
    Livewire::test(ViewConsultation::class, ['record' => $deal->getKey()])
        ->assertActionHidden('buatProyek');
});

it('creates the draft project from the consultation via the action', function () {
    $manager = bridgeManager(Bidang::Cufid);
    $konsumen = bridgeKonsumen();
    $this->actingAs($manager);

    $deal = Consultation::factory()->ownedBy($konsumen)->claimedBy($manager)
        ->inBidang(Bidang::Cufid)->status(ConsultationStatus::Deal)->create();

    Livewire::test(ViewConsultation::class, ['record' => $deal->getKey()])
        ->callAction('buatProyek', data: ['title' => 'Proyek Uji'])
        ->assertHasNoActionErrors();

    $project = Project::where('consultation_id', $deal->id)->sole();
    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->konsumen_id)->toBe($konsumen->id)
        ->and($project->manager_id)->toBe($manager->id)
        ->and($project->bidang)->toBe(Bidang::Cufid)
        ->and($project->title)->toBe('Proyek Uji');
});
