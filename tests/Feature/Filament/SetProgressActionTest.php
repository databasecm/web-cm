<?php

use App\Enums\Bidang;
use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
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

function progressManager(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

it('lets a managing Manager set progress and opens the progress installment at ≥ 50%', function () {
    $manager = progressManager(Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->managedBy($manager)
        ->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, PaymentScheme::Termin3);

    $this->actingAs($manager);

    Livewire::test(ViewProject::class, ['record' => $project->getKey()])
        ->assertActionVisible('aturProgres')
        ->callAction('aturProgres', data: ['progress_percent' => 50])
        ->assertHasNoActionErrors();

    $project->refresh();
    expect($project->progress_percent)->toBe('50.00')
        ->and($project->installments()->where('due_condition', DueCondition::Progress50->value)->sole()->status)
        ->toBe(InstallmentStatus::Unlocked)
        // bast stays locked
        ->and($project->installments()->where('due_condition', DueCondition::Bast->value)->sole()->status)
        ->toBe(InstallmentStatus::Locked);
});
