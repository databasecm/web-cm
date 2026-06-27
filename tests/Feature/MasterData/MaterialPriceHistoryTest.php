<?php

use App\Enums\MaterialSource;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\MaterialPriceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = new MaterialPriceService;
});

// ---------------------------------------------------------------------------
// Casts
// ---------------------------------------------------------------------------

it('casts price, is_sni and source', function () {
    $material = Material::factory()->fromSupplier()->priced(70000)->create(['is_sni' => true]);

    expect($material->price)->toBe('70000.00')
        ->and($material->is_sni)->toBeTrue()
        ->and($material->source)->toBe(MaterialSource::Supplier);
});

// ---------------------------------------------------------------------------
// Journalling — one row on create, one per change
// ---------------------------------------------------------------------------

it('records an initial price-history row when a material is created', function () {
    $material = Material::factory()->priced(50000)->create();

    $history = $material->priceHistory()->get();
    expect($history)->toHaveCount(1)
        ->and($history->first()->price)->toBe('50000.00');
});

it('records a new history row on each price change via the service', function () {
    $actor = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    $material = Material::factory()->priced(50000)->create();

    $this->service->change($material, 60000, $actor);
    $this->service->change($material, 75000, $actor);

    $history = $material->priceHistory()->orderBy('recorded_at')->orderBy('id')->get();
    expect($history)->toHaveCount(3) // initial + 2 changes
        ->and($history->pluck('price')->all())->toBe(['50000.00', '60000.00', '75000.00'])
        ->and($history->last()->changed_by)->toBe($actor->id);
});

// ---------------------------------------------------------------------------
// Idempotency — one change = one row; no double via service + observer
// ---------------------------------------------------------------------------

it('does not record a row when the price is unchanged', function () {
    $material = Material::factory()->priced(50000)->create();

    $this->service->change($material, 50000); // same value
    $this->service->change($material, '50000.00'); // same value, string form

    expect($material->priceHistory()->count())->toBe(1);
});

it('records exactly one row per change even on a direct model update (safety net, no double)', function () {
    $material = Material::factory()->priced(50000)->create();

    // Bypassing the service entirely — the observer still journals it, once.
    $material->update(['price' => 65000]);

    $history = $material->priceHistory()->orderBy('id')->get();
    expect($history)->toHaveCount(2)
        ->and($history->last()->price)->toBe('65000.00');
});

it('does not journal when a non-price field changes', function () {
    $material = Material::factory()->priced(50000)->create();

    $material->update(['brand' => 'Merk Baru', 'is_sni' => true]);

    expect($material->priceHistory()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Relations
// ---------------------------------------------------------------------------

it('relates history to its material and cascades on delete', function () {
    $material = Material::factory()->create();
    $id = $material->id;

    expect(MaterialPriceHistory::where('material_id', $id)->count())->toBe(1);

    $material->forceDelete();

    expect(MaterialPriceHistory::where('material_id', $id)->count())->toBe(0);
});
