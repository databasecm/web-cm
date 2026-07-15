<?php

use App\Enums\Bidang;
use App\Enums\MaterialSource;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function mmMandor(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'mandor')->value('id'), 'bidang' => $bidang]);
}

function mmRoled(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

// ---------------------------------------------------------------------------
// A Mandor adds a field material to the catalog — no cash ever posted
// ---------------------------------------------------------------------------

it('lets a Mandor add a field material to the catalog, attributed and price-journalled', function () {
    $mandor = mmMandor(Bidang::Cufid);
    Sanctum::actingAs($mandor);

    $this->postJson('/api/v1/mandor/materials', [
        'name' => 'Paku 5cm',
        'brand' => 'Cap Gajah',
        'unit' => 'kg',
        'price' => '18500',
        'is_sni' => true,
        'supplier_name' => 'Toko Bangunan Jaya',
        'supplier_address' => 'Jl. Raya Bogor',
    ])->assertCreated()->assertJsonPath('data.source', 'internal');

    $material = Material::sole();
    expect($material->name)->toBe('Paku 5cm')
        ->and($material->source)->toBe(MaterialSource::Internal)
        ->and((int) $material->input_by)->toBe($mandor->id)
        ->and((float) $material->price)->toBe(18500.0);

    // The initial price is journalled by the observer, attributed to the Mandor.
    $history = MaterialPriceHistory::where('material_id', $material->id)->sole();
    expect((int) $history->changed_by)->toBe($mandor->id)
        ->and((float) $history->price)->toBe(18500.0);

    // JEBAKAN 6-5b: catalog input NEVER posts a cash-book transaction.
    expect(Transaction::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Channel guard — only a Mandor account may reach this endpoint
// ---------------------------------------------------------------------------

it('forbids non-Mandor accounts from the field material endpoint', function () {
    $this->postJson('/api/v1/mandor/materials', ['name' => 'X', 'price' => '1'])->assertUnauthorized();

    foreach (['konsumen', 'mitra_pembiayaan'] as $name) {
        Sanctum::actingAs(mmRoled($name));
        $this->postJson('/api/v1/mandor/materials', ['name' => 'X', 'price' => '1'])->assertForbidden();
    }

    expect(Material::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Policy — Mandor may create (add) but not edit/delete; Mitra/Konsumen nothing
// ---------------------------------------------------------------------------

it('authorizes catalog add to Mandor and managers, but not edit/delete to Mandor', function () {
    $mandor = mmMandor(Bidang::Cufid);
    $material = Material::factory()->create();

    // Mandor may ADD, but not edit/delete existing catalog entries.
    expect($mandor->can('create', Material::class))->toBeTrue()
        ->and($mandor->can('update', $material))->toBeFalse()
        ->and($mandor->can('delete', $material))->toBeFalse();

    // Managers/overseers keep full manage; Mitra & Konsumen have nothing.
    expect(mmRoled('manager')->can('create', Material::class))->toBeTrue()
        ->and(mmRoled('owner')->can('create', Material::class))->toBeTrue()
        ->and(mmRoled('mitra_pembiayaan')->can('create', Material::class))->toBeFalse()
        ->and(mmRoled('konsumen')->can('create', Material::class))->toBeFalse();
});
