<?php

use App\Enums\AhsapComponentType;
use App\Enums\Bidang;
use App\Filament\Resources\AhsapResource;
use App\Filament\Resources\AhsapResource\Pages\CreateAhsap;
use App\Filament\Resources\AhsapResource\Pages\EditAhsap;
use App\Filament\Resources\AhsapResource\Pages\ListAhsap;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use App\Services\MaterialPriceService;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('sistem'));
});

function ahsapUser(string $roleName, ?Bidang $bidang = null): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $roleName)->value('id'),
        'bidang' => $bidang,
    ]);
}

// ---------------------------------------------------------------------------
// Visibility & CRUD capability per role
// ---------------------------------------------------------------------------

it('shows AHSAP to every internal account but hides it from Mitra and Konsumen', function () {
    foreach (['owner', 'direktur', 'manager', 'finance', 'hr', 'mandor'] as $name) {
        $this->actingAs(ahsapUser($name, in_array($name, ['manager', 'mandor'], true) ? Bidang::Cufid : null));
        expect(AhsapResource::canViewAny())->toBeTrue("{$name} should view AHSAP");
    }

    foreach (['mitra_pembiayaan', 'supplier', 'konsumen'] as $name) {
        $this->actingAs(ahsapUser($name));
        expect(AhsapResource::canViewAny())->toBeFalse("{$name} must not view AHSAP");
    }
});

it('lets only Owner, Direktur and Manager create AHSAP', function () {
    foreach (['owner', 'direktur', 'manager'] as $name) {
        $this->actingAs(ahsapUser($name, $name === 'manager' ? Bidang::Cufid : null));
        expect(AhsapResource::canCreate())->toBeTrue("{$name} should create");
    }

    foreach (['finance', 'hr', 'mandor'] as $name) {
        $this->actingAs(ahsapUser($name, $name === 'mandor' ? Bidang::Cufid : null));
        expect(AhsapResource::canCreate())->toBeFalse("{$name} must not create");
    }
});

// ---------------------------------------------------------------------------
// Component builder computes base_price
// ---------------------------------------------------------------------------

it('computes base_price from the component builder on create', function () {
    $this->actingAs(ahsapUser('owner'));

    Livewire::test(CreateAhsap::class)
        ->fillForm([
            'code' => 'AHS.900',
            'name' => 'Pasang dinding',
            'bidang' => Bidang::Cufid->value,
            'unit' => 'm²',
            'components' => [
                ['type' => AhsapComponentType::Upah->value, 'description' => 'Tukang', 'coefficient' => 2, 'unit_price' => 50000],
                ['type' => AhsapComponentType::Alat->value, 'description' => 'Molen', 'coefficient' => 1, 'unit_price' => 20000],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ahsap = Ahsap::where('code', 'AHS.900')->sole();
    // 2×50.000 + 1×20.000 = 120.000
    expect($ahsap->base_price)->toBe('120000.00')
        ->and($ahsap->components()->count())->toBe(2);
});

it('snapshots a material component unit_price from the Material database on create', function () {
    $material = Material::factory()->priced(70000)->create();
    $this->actingAs(ahsapUser('owner'));

    Livewire::test(CreateAhsap::class)
        ->fillForm([
            'code' => 'AHS.901',
            'name' => 'Cor beton',
            'bidang' => Bidang::Cc->value,
            'unit' => 'm³',
            'components' => [
                // unit_price intentionally left 0 — the snapshot must override it.
                ['type' => AhsapComponentType::Material->value, 'material_id' => $material->id, 'coefficient' => 2, 'unit_price' => 0],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ahsap = Ahsap::where('code', 'AHS.901')->sole();
    expect($ahsap->components()->first()->unit_price)->toBe('70000.00')
        ->and($ahsap->base_price)->toBe('140000.00');
});

// ---------------------------------------------------------------------------
// Bidang scope
// ---------------------------------------------------------------------------

it('shows a Manager only its own bidang AHSAP; overseers see all', function () {
    $cufid = Ahsap::factory()->inBidang(Bidang::Cufid)->create();
    $cc = Ahsap::factory()->inBidang(Bidang::Cc)->create();

    $this->actingAs(ahsapUser('manager', Bidang::Cufid));
    Livewire::test(ListAhsap::class)
        ->assertCanSeeTableRecords([$cufid])
        ->assertCanNotSeeTableRecords([$cc]);

    $this->actingAs(ahsapUser('direktur'));
    Livewire::test(ListAhsap::class)
        ->assertCanSeeTableRecords([$cufid, $cc]);
});

it('forbids a Manager editing AHSAP from another bidang', function () {
    $cc = Ahsap::factory()->inBidang(Bidang::Cc)->create();
    $this->actingAs(ahsapUser('manager', Bidang::Cufid));

    expect(AhsapResource::canEdit($cc))->toBeFalse();

    // Scoped out of the query entirely → opening it resolves to nothing.
    expect(fn () => Livewire::test(EditAhsap::class, ['record' => $cc->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('locks a creating Manager to its own bidang', function () {
    $this->actingAs(ahsapUser('manager', Bidang::Cufid));

    Livewire::test(CreateAhsap::class)
        ->fillForm([
            'code' => 'AHS.902',
            'name' => 'Plesteran',
            'unit' => 'm²',
            'components' => [
                ['type' => AhsapComponentType::Upah->value, 'description' => 'Tukang', 'coefficient' => 1, 'unit_price' => 40000],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Ahsap::where('code', 'AHS.902')->sole()->bidang)->toBe(Bidang::Cufid);
});

// ---------------------------------------------------------------------------
// Resync action surfaces the staleness flow
// ---------------------------------------------------------------------------

it('offers and runs the resync action only on a flagged AHSAP', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = Ahsap::factory()->inBidang(Bidang::Cufid)->create();
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(1)->create();

    $manager = ahsapUser('manager', Bidang::Cufid);
    $this->actingAs($manager);

    // Not flagged yet → action hidden.
    Livewire::test(ListAhsap::class)->assertTableActionHidden('resync', $ahsap);

    // A price change flags it.
    (new MaterialPriceService)->change($material, 90000, $manager);
    expect($ahsap->refresh()->needs_review)->toBeTrue();

    Livewire::test(ListAhsap::class)
        ->assertTableActionVisible('resync', $ahsap)
        ->callTableAction('resync', $ahsap);

    $ahsap->refresh();
    expect($ahsap->needs_review)->toBeFalse()
        ->and($ahsap->base_price)->toBe('90000.00');
});
