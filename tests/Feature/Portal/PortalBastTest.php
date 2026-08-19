<?php

use App\Enums\BastStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Livewire\Portal\ProjectDetail;
use App\Livewire\Portal\ProjectPayments;
use App\Models\Bast;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\CheckoutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

if (! function_exists('portalConsumer')) {
    function portalConsumer(bool $verified = true): User
    {
        $factory = User::factory();

        if (! $verified) {
            $factory = $factory->unverified();
        }

        return $factory->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
    }
}

if (! function_exists('portalStaff')) {
    function portalStaff(): User
    {
        return User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    }
}

if (! function_exists('portalOwnedProject')) {
    function portalOwnedProject(User $consumer, array $attributes = []): Project
    {
        return Project::factory()->create(array_merge(['konsumen_id' => $consumer->id], $attributes));
    }
}

// ---------------------------------------------------------------------------
// 1) Consumer signs — via the same gate + service
// ---------------------------------------------------------------------------

it('records the consumer BAST signature through the service', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $bast = Bast::factory()->for($project)->create(); // draft, neither party signed

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project])
        ->call('signBast', $bast->id)
        ->assertHasNoErrors();

    $bast->refresh();

    expect($bast->signed_customer)->toBeTrue()
        ->and((int) $bast->signed_customer_by)->toBe($me->id)
        ->and($bast->status)->toBe(BastStatus::Draft); // company not signed yet
});

// ---------------------------------------------------------------------------
// 2) §7 END-TO-END from the portal:
//    consumer signature completes the BAST → pelunasan unlocks → chargeable
// ---------------------------------------------------------------------------

it('completes §7 end-to-end: signing opens the pelunasan for payment', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me, ['contract_value' => '1000000']);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Termin3);
    $pelunasan = $project->installments()->where('term_no', 3)->firstOrFail(); // bast term, Locked

    // Company has already signed; consumer is the last signature.
    $bast = Bast::factory()->for($project)->signedByCompany()->create();

    // Before signing: §7 holds — the locked pelunasan cannot be charged.
    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project])
        ->call('charge', $pelunasan->id);
    expect($pelunasan->refresh()->status)->toBe(InstallmentStatus::Locked)
        ->and($pelunasan->gateway_ref)->toBeNull();

    // Consumer signs → both parties signed → BAST signed → pelunasan unlocked.
    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project])
        ->call('signBast', $bast->id)
        ->assertHasNoErrors();

    expect($bast->refresh()->status)->toBe(BastStatus::Signed)
        ->and($pelunasan->refresh()->status)->toBe(InstallmentStatus::Unlocked);

    // Now the pelunasan CAN be charged from the portal (refused a moment ago).
    Livewire::actingAs($me)->test(ProjectPayments::class, ['project' => $project])
        ->call('charge', $pelunasan->id)
        ->assertHasNoErrors();

    expect($pelunasan->refresh()->gateway_ref)->not->toBeNull()
        ->and($pelunasan->va_number)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 3) The hard line: cannot sign another consumer's BAST
// ---------------------------------------------------------------------------

it('forbids signing another consumer’s BAST (no action bypass)', function () {
    $me = portalConsumer();
    $mine = portalOwnedProject($me);

    $theirBast = Bast::factory()->for(portalOwnedProject(portalConsumer()))->create();

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $mine])
        ->call('signBast', $theirBast->id)
        ->assertForbidden();

    expect($theirBast->refresh()->signed_customer)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4) Idempotent: a second consumer signature does not double-unlock
// ---------------------------------------------------------------------------

it('is idempotent on a repeated consumer signature', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me, ['contract_value' => '1000000']);
    app(CheckoutService::class)->checkout($project, PaymentScheme::Termin3);
    $pelunasan = $project->installments()->where('term_no', 3)->firstOrFail();
    $bast = Bast::factory()->for($project)->signedByCompany()->create();

    $component = Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project]);

    $component->call('signBast', $bast->id)->assertHasNoErrors();
    expect($bast->refresh()->status)->toBe(BastStatus::Signed)
        ->and($pelunasan->refresh()->status)->toBe(InstallmentStatus::Unlocked);

    // Sign again → safe no-op; still signed, pelunasan still (singly) unlocked.
    $component->call('signBast', $bast->id)->assertHasNoErrors();
    expect($bast->refresh()->status)->toBe(BastStatus::Signed)
        ->and($pelunasan->refresh()->status)->toBe(InstallmentStatus::Unlocked);
});

// ---------------------------------------------------------------------------
// 5) BAST PDF — signed + owner only
// ---------------------------------------------------------------------------

it('lets the owner download a signed BAST PDF', function () {
    $me = portalConsumer();
    $bast = Bast::factory()->for(portalOwnedProject($me))->signed()->create();

    $this->actingAs($me)
        ->get(route('portal.bast.pdf', $bast))
        ->assertOk()
        ->assertDownload();
});

it('forbids downloading an unsigned (draft) BAST PDF', function () {
    $me = portalConsumer();
    $bast = Bast::factory()->for(portalOwnedProject($me))->create(); // draft

    $this->actingAs($me)
        ->get(route('portal.bast.pdf', $bast))
        ->assertForbidden();
});

it('forbids another consumer / staff from downloading a BAST PDF', function () {
    $bast = Bast::factory()->for(portalOwnedProject(portalConsumer()))->signed()->create();

    $this->actingAs(portalConsumer())->get(route('portal.bast.pdf', $bast))->assertForbidden();
    $this->actingAs(portalStaff())->get(route('portal.bast.pdf', $bast))->assertForbidden();
});
