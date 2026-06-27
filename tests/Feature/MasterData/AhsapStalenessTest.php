<?php

use App\Enums\AhsapComponentType;
use App\Enums\Bidang;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use App\Services\AhsapReviewService;
use App\Services\MaterialPriceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->priceService = new MaterialPriceService;
    $this->review = app(AhsapReviewService::class);
    $this->actor = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
});

/** An AHSAP whose only component uses the given material at coefficient 1. */
function ahsapUsing(Material $material, Bidang $bidang = Bidang::Cufid): Ahsap
{
    $ahsap = Ahsap::factory()->inBidang($bidang)->create();
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(1)->create();

    return $ahsap->refresh();
}

// ---------------------------------------------------------------------------
// Flag cycle
// ---------------------------------------------------------------------------

it('flags an AHSAP for review when a material it uses changes price', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = ahsapUsing($material);

    expect($ahsap->needs_review)->toBeFalse();

    $this->priceService->change($material, 80000, $this->actor);

    $ahsap->refresh();
    expect($ahsap->needs_review)->toBeTrue()
        ->and($ahsap->review_reason)->toContain($material->name)
        ->and($ahsap->review_requested_at)->not->toBeNull();
});

it('does not change base_price when flagging — only marks it', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = ahsapUsing($material);
    expect($ahsap->base_price)->toBe('70000.00');

    $this->priceService->change($material, 999999, $this->actor);

    expect($ahsap->refresh()->base_price)->toBe('70000.00') // unchanged — snapshot holds
        ->and($ahsap->needs_review)->toBeTrue();
});

it('flagging writes no audit row (only the deliberate resync does)', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = ahsapUsing($material);

    $this->priceService->change($material, 80000, $this->actor);

    // The only AHSAP audit row is the legitimate 'created' from factory build;
    // flagging adds no 'updated' (or any other) row.
    expect(AuditLog::where('entity', Ahsap::class)->where('entity_id', $ahsap->id)
        ->where('action', '!=', 'created')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Idempotency — one change = one flag, no re-stamp while already flagged
// ---------------------------------------------------------------------------

it('is idempotent: a second change while already flagged does not re-stamp', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = ahsapUsing($material);

    $this->priceService->change($material, 80000, $this->actor);
    $firstStamp = $ahsap->refresh()->review_requested_at;

    $this->priceService->change($material, 90000, $this->actor);
    $secondStamp = $ahsap->refresh()->review_requested_at;

    expect($ahsap->needs_review)->toBeTrue()
        ->and($secondStamp->equalTo($firstStamp))->toBeTrue();
});

it('does not flag when the price is unchanged (service no-op)', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = ahsapUsing($material);

    $this->priceService->change($material, 70000, $this->actor); // same value

    expect($ahsap->refresh()->needs_review)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Isolation — only AHSAP that actually use the material are flagged
// ---------------------------------------------------------------------------

it('does not flag an AHSAP that does not use the changed material', function () {
    $material = Material::factory()->priced(70000)->create();
    $other = Material::factory()->priced(50000)->create();

    $using = ahsapUsing($material, Bidang::Cufid);
    $notUsing = ahsapUsing($other, Bidang::Cc); // different material & bidang

    $this->priceService->change($material, 80000, $this->actor);

    expect($using->refresh()->needs_review)->toBeTrue()
        ->and($notUsing->refresh()->needs_review)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Resync — clears flag, recomputes, audits
// ---------------------------------------------------------------------------

it('resync pulls current prices, recomputes base_price, clears the flag and audits', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = ahsapUsing($material); // base 70.000, snapshot 70.000

    $this->priceService->change($material, 90000, $this->actor);
    expect($ahsap->refresh()->needs_review)->toBeTrue()
        ->and($ahsap->base_price)->toBe('70000.00'); // still old until resync

    $this->review->resync($ahsap, $this->actor);

    $ahsap->refresh();
    expect($ahsap->needs_review)->toBeFalse()
        ->and($ahsap->review_reason)->toBeNull()
        ->and($ahsap->review_requested_at)->toBeNull()
        ->and($ahsap->base_price)->toBe('90000.00') // pulled current price
        ->and($ahsap->components()->first()->unit_price)->toBe('90000.00');

    $audit = AuditLog::where('entity', Ahsap::class)
        ->where('entity_id', $ahsap->id)
        ->where('action', 'ahsap_resynced')
        ->sole();

    expect($audit->user_id)->toBe($this->actor->id)
        ->and($audit->before['base_price'])->toBe('70000.00')
        ->and($audit->after['base_price'])->toBe('90000.00');
});

it('resync leaves upah and alat components untouched', function () {
    $material = Material::factory()->priced(70000)->create();
    $ahsap = Ahsap::factory()->create();
    AhsapComponent::factory()->for($ahsap)->material($material)->coefficient(1)->create();
    $upah = AhsapComponent::factory()->for($ahsap)->ofType(AhsapComponentType::Upah)->coefficient(1)->unitPrice(50000)->create();

    $this->priceService->change($material, 90000, $this->actor);
    $this->review->resync($ahsap->refresh(), $this->actor);

    expect($upah->refresh()->unit_price)->toBe('50000.00')
        ->and($ahsap->refresh()->base_price)->toBe('140000.00'); // 90.000 + 50.000
});
