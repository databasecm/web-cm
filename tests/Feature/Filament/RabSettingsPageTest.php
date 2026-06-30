<?php

use App\Enums\Bidang;
use App\Filament\Pages\RabSettings;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function settingsUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

it('admits only Owner and Direktur to the RAB settings page', function () {
    foreach (['owner', 'direktur'] as $name) {
        $this->actingAs(settingsUser($name));
        expect(RabSettings::canAccess())->toBeTrue("{$name} should manage settings");
    }

    foreach (['manager', 'finance', 'hr', 'mandor', 'mitra_pembiayaan', 'konsumen'] as $name) {
        $this->actingAs(settingsUser($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(RabSettings::canAccess())->toBeFalse("{$name} must not manage settings");
    }
});

it('lets an Owner change the global RAB defaults', function () {
    $this->actingAs(settingsUser('owner'));

    Livewire::test(RabSettings::class)
        ->fillForm([
            'margin_percent' => '12',
            'ppn_percent' => '11',
            'overhead_percent' => '8',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = new SettingService;
    expect($settings->marginPercentDefault())->toBe('12')
        ->and($settings->overheadPercentDefault())->toBe('8');
});
