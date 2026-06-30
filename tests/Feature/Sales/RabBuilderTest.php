<?php

use App\Enums\AhsapComponentType;
use App\Enums\Bidang;
use App\Enums\RabStatus;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\Material;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AhsapReviewService;
use App\Services\MaterialPriceService;
use App\Services\RabBuilder;
use App\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingSeeder::class); // margin 10, ppn 11, overhead 5
    $this->builder = app(RabBuilder::class);
});

/** A material-backed AHSAP (its base_price is one material at $coef × price). */
function materialAhsap(Material $material, float $coef = 1, Bidang $bidang = Bidang::Cufid): Ahsap
{
    $ahsap = Ahsap::factory()->inBidang($bidang)->create();
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient($coef)->create();

    return $ahsap->refresh();
}

/** An upah (labour) AHSAP. */
function upahAhsap(float $price, Bidang $bidang = Bidang::Cufid): Ahsap
{
    $ahsap = Ahsap::factory()->inBidang($bidang)->create();
    AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Upah)->coefficient(1)->unitPrice($price)->create();

    return $ahsap->refresh();
}

// ---------------------------------------------------------------------------
// TEST 1 — grand_total is correct (BigDecimal), material + labour mix
// ---------------------------------------------------------------------------

it('computes the RAB totals and grand_total from a material+labour mix', function () {
    $material = Material::factory()->priced(70000)->create();
    $materialItem = materialAhsap($material);   // base_price 70.000 (material)
    $labourItem = upahAhsap(50000);             // base_price 50.000 (upah)
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();

    $rab = $this->builder->build($project, [
        ['ahsap_id' => $materialItem->id, 'volume' => 2], // 2 × 70.000 = 140.000 material
        ['ahsap_id' => $labourItem->id, 'volume' => 3],   // 3 × 50.000 = 150.000 upah
    ]);

    // base = 290.000; overhead 5% = 14.500; margin 10% of 304.500 = 30.450;
    // ppn 11% of 334.950 = 36.844,50; grand = 371.794,50
    expect($rab->total_material)->toBe('140000.00')
        ->and($rab->total_upah)->toBe('150000.00')
        ->and($rab->overhead)->toBe('14500.00')
        ->and($rab->margin)->toBe('30450.00')
        ->and($rab->ppn)->toBe('36844.50')
        ->and($rab->grand_total)->toBe('371794.50')
        ->and($rab->version)->toBe(1)
        ->and($rab->status)->toBe(RabStatus::Draft)
        ->and($rab->items)->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// TEST 2 — snapshot proven: AHSAP resync never moves an existing RAB;
// a NEW version picks up the latest price
// ---------------------------------------------------------------------------

it('freezes a RAB against later AHSAP price changes; a new version picks up the latest', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = materialAhsap($material); // base_price 70.000
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();

    $v1 = $this->builder->build($project, [['ahsap_id' => $ahsap->id, 'volume' => 1]]);
    $v1Grand = $v1->grand_total;
    $v1UnitPrice = $v1->items()->first()->unit_price;
    expect($v1UnitPrice)->toBe('70000.00');

    // The material price jumps and the AHSAP is resynced → base_price = 100.000.
    $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id'), 'bidang' => Bidang::Cufid]);
    (new MaterialPriceService)->change($material, 100000, $manager);
    app(AhsapReviewService::class)->resync($ahsap->refresh(), $manager);
    expect($ahsap->refresh()->base_price)->toBe('100000.00');

    // The existing RAB is unchanged — its snapshot holds.
    $v1->refresh();
    expect($v1->items()->first()->unit_price)->toBe('70000.00')
        ->and($v1->grand_total)->toBe($v1Grand);

    // A fresh build (version 2) reflects the new price.
    $v2 = $this->builder->build($project, [['ahsap_id' => $ahsap->id, 'volume' => 1]]);
    expect($v2->version)->toBe(2)
        ->and($v2->items()->first()->unit_price)->toBe('100000.00')
        ->and($v2->total_material)->toBe('100000.00')
        ->and($v2->grand_total)->not->toBe($v1Grand);
});

// ---------------------------------------------------------------------------
// TEST 3 — rate override is snapshotted; changing the global setting later
// does not move an existing RAB
// ---------------------------------------------------------------------------

it('snapshots overridden rates and ignores later global setting changes', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $labour = upahAhsap(100000);

    // Override PPN to 0 and margin to 20 for this build.
    $rab = $this->builder->build($project, [
        ['ahsap_id' => $labour->id, 'volume' => 1], // base 100.000 upah
    ], ['margin_percent' => '20', 'ppn_percent' => '0', 'overhead_percent' => '0']);

    // base 100.000; overhead 0; margin 20% = 20.000; ppn 0; grand 120.000
    expect($rab->margin_percent)->toBe('20.0000')
        ->and($rab->ppn_percent)->toBe('0.0000')
        ->and($rab->margin)->toBe('20000.00')
        ->and($rab->ppn)->toBe('0.00')
        ->and($rab->grand_total)->toBe('120000.00');

    // Changing the global defaults afterwards must not move this RAB.
    app(SettingService::class)->set(SettingService::KEY_PPN, '50');

    expect($rab->refresh()->ppn_percent)->toBe('0.0000')
        ->and($rab->grand_total)->toBe('120000.00');
});

it('falls back to the global default rates when none are overridden', function () {
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $labour = upahAhsap(100000);

    $rab = $this->builder->build($project, [['ahsap_id' => $labour->id, 'volume' => 1]]);

    expect($rab->margin_percent)->toBe('10.0000')
        ->and($rab->ppn_percent)->toBe('11.0000')
        ->and($rab->overhead_percent)->toBe('5.0000');
});
