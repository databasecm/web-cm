<?php

use App\Enums\Bidang;
use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\MaterialResource\Pages\CreateMaterial;
use App\Filament\Resources\MaterialResource\Pages\EditMaterial;
use App\Filament\Resources\MaterialResource\Pages\ListMaterials;
use App\Filament\Resources\MaterialResource\RelationManagers\PriceHistoryRelationManager;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\Material;
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

function materialUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// Visibility & CRUD capability
// ---------------------------------------------------------------------------

it('shows materials to every internal account, hidden from Mitra/Konsumen', function () {
    foreach (['owner', 'direktur', 'manager', 'finance', 'hr', 'mandor'] as $name) {
        $this->actingAs(materialUser($name, in_array($name, ['manager', 'mandor'], true) ? Bidang::Cufid : null));
        expect(MaterialResource::canViewAny())->toBeTrue("{$name} should view");
    }

    foreach (['mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $this->actingAs(materialUser($name));
        expect(MaterialResource::canViewAny())->toBeFalse("{$name} must not view");
    }
});

it('lets only Owner, Direktur and Manager manage materials', function () {
    $material = Material::factory()->create();

    foreach (['owner', 'direktur', 'manager'] as $name) {
        $this->actingAs(materialUser($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(MaterialResource::canCreate())->toBeTrue("{$name} create")
            ->and(MaterialResource::canEdit($material))->toBeTrue("{$name} edit");
    }

    foreach (['finance', 'hr'] as $name) {
        $this->actingAs(materialUser($name));
        expect(MaterialResource::canCreate())->toBeFalse("{$name} create")
            ->and(MaterialResource::canEdit($material))->toBeFalse("{$name} edit");
    }

    // Mandor may ADD field materials (Fase 6-5b) but still cannot edit them.
    $this->actingAs(materialUser('mandor', Bidang::Cufid));
    expect(MaterialResource::canCreate())->toBeTrue('mandor create')
        ->and(MaterialResource::canEdit($material))->toBeFalse('mandor edit');
});

// ---------------------------------------------------------------------------
// Create records the initial price + attributes the inputter
// ---------------------------------------------------------------------------

it('creates a material, attributes input_by and records the initial price', function () {
    $owner = materialUser('owner');
    $this->actingAs($owner);

    Livewire::test(CreateMaterial::class)
        ->fillForm([
            'name' => 'Semen',
            'brand' => 'Tiga Roda',
            'unit' => 'sak',
            'price' => 70000,
            'is_sni' => true,
            'source' => 'internal',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $material = Material::where('name', 'Semen')->sole();
    expect($material->input_by)->toBe($owner->id)
        ->and($material->is_sni)->toBeTrue()
        ->and($material->priceHistory()->count())->toBe(1)
        ->and($material->priceHistory()->first()->price)->toBe('70000.00');
});

// ---------------------------------------------------------------------------
// Price history relation is read-only and shows the timeline
// ---------------------------------------------------------------------------

it('shows the price-history timeline read-only', function () {
    $manager = materialUser('manager', Bidang::Cufid);
    $this->actingAs($manager);
    $material = Material::factory()->priced(50000)->create();

    $first = $material->priceHistory()->first();

    Livewire::test(PriceHistoryRelationManager::class, [
        'ownerRecord' => $material,
        'pageClass' => EditMaterial::class,
    ])
        ->assertCanSeeTableRecords([$first])
        ->assertTableActionDoesNotExist('delete');
});

// ---------------------------------------------------------------------------
// "Ubah Harga" routes through the service → history + AHSAP staleness (2A-3)
// ---------------------------------------------------------------------------

it('changes price via the Ubah Harga action, recording history and flagging dependent AHSAP', function () {
    $manager = materialUser('manager', Bidang::Cufid);
    $this->actingAs($manager);

    $material = Material::factory()->priced(70000)->create();
    $ahsap = Ahsap::factory()->inBidang(Bidang::Cufid)->create();
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(1)->create();

    expect($ahsap->refresh()->needs_review)->toBeFalse();

    Livewire::test(ListMaterials::class)
        ->callTableAction('ubahHarga', $material, data: ['price' => 95000]);

    $material->refresh();
    expect($material->price)->toBe('95000.00')
        // routed through the service → journalled + attributed to the actor
        ->and($material->priceHistory()->count())->toBe(2)
        ->and($material->priceHistory()->first()->changed_by)->toBe($manager->id)
        // and the 2A-3 chain fired: dependent AHSAP flagged for review
        ->and($ahsap->refresh()->needs_review)->toBeTrue();

    // The snapshot in the AHSAP is untouched until an explicit resync.
    expect($ahsap->base_price)->toBe('70000.00');
});
