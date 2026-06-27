<?php

use App\Enums\AhsapComponentType;
use App\Enums\Bidang;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use App\Services\MaterialPriceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// ---------------------------------------------------------------------------
// base_price = Σ(coefficient × unit_price), mixed component types
// ---------------------------------------------------------------------------

it('computes base_price from material, upah and alat components', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = Ahsap::factory()->inBidang(Bidang::Cufid)->create();

    // material: 2 × 70.000 = 140.000 (unit_price snapshotted from material)
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(2)->create();
    // upah: 1 × 50.000 = 50.000
    AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Upah)->coefficient(1)->unitPrice(50000)->create();
    // alat: 0.5 × 20.000 = 10.000
    AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Alat)->coefficient(0.5)->unitPrice(20000)->create();

    expect($ahsap->refresh()->base_price)->toBe('200000.00');
});

it('recomputes base_price when a component changes or is removed', function () {
    $ahsap = Ahsap::factory()->create();
    $a = AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Upah)->coefficient(1)->unitPrice(30000)->create();
    $b = AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Alat)->coefficient(1)->unitPrice(20000)->create();

    expect($ahsap->refresh()->base_price)->toBe('50000.00');

    $a->update(['coefficient' => 3]); // 3 × 30.000 = 90.000  (+ 20.000)
    expect($ahsap->refresh()->base_price)->toBe('110000.00');

    $b->delete(); // back to 90.000
    expect($ahsap->refresh()->base_price)->toBe('90000.00');
});

// ---------------------------------------------------------------------------
// Snapshot stability — the heart of ADR-0004
// ---------------------------------------------------------------------------

it('snapshots material unit_price at add time', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = Ahsap::factory()->create();

    $component = AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(1)->create();

    expect($component->refresh()->unit_price)->toBe('70000.00')
        ->and($ahsap->refresh()->base_price)->toBe('70000.00');
});

it('does not change an existing AHSAP when the material price later moves', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = Ahsap::factory()->create();
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(2)->create();

    expect($ahsap->refresh()->base_price)->toBe('140000.00');

    // Material price jumps later — the snapshot (and base_price) must hold.
    $actor = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    (new MaterialPriceService)->change($material, 99999, $actor);

    expect($ahsap->refresh()->base_price)->toBe('140000.00')
        ->and($ahsap->components()->first()->unit_price)->toBe('70000.00')
        // the price movement is still journalled on the material itself
        ->and($material->priceHistory()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Routine recalculation does not spam the audit trail (audit only on resync)
// ---------------------------------------------------------------------------

it('does not audit routine base_price recalculation', function () {
    $ahsap = Ahsap::factory()->create(); // create() is audited once
    AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Upah)->unitPrice(40000)->create();

    $updates = AuditLog::where('entity', Ahsap::class)
        ->where('entity_id', $ahsap->id)
        ->where('action', 'updated')
        ->count();

    expect($updates)->toBe(0)
        ->and($ahsap->refresh()->base_price)->toBe('40000.00');
});
