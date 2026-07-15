<?php

use App\Enums\Bidang;
use App\Enums\MaterialSource;
use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\MaterialResource\Pages\CreateMaterial;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Role;
use App\Models\Transaction;
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

// A Mandor may add a catalog material from the panel too (same guards, no cash).
it('lets a Mandor add a field material via the panel, attributed and cash-free', function () {
    $mandor = User::factory()->create([
        'role_id' => Role::where('name', 'mandor')->value('id'),
        'bidang' => Bidang::Cufid,
    ]);
    $this->actingAs($mandor);

    expect(MaterialResource::canCreate())->toBeTrue();

    Livewire::test(CreateMaterial::class)
        ->fillForm([
            'name' => 'Kawat Bendrat',
            'unit' => 'kg',
            'price' => '22000',
            'source' => MaterialSource::Internal->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $material = Material::sole();
    expect((int) $material->input_by)->toBe($mandor->id)
        ->and($material->source)->toBe(MaterialSource::Internal)
        ->and(MaterialPriceHistory::where('material_id', $material->id)->count())->toBe(1)
        ->and(Transaction::count())->toBe(0); // catalog input never touches the cash book
});
