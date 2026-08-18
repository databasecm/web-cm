<?php

use App\Enums\DesignStatus;
use App\Enums\ProjectStatus;
use App\Enums\RabStatus;
use App\Livewire\Portal\ProjectDetail;
use App\Models\Design;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// Guarded so the file also runs in isolation (helpers are declared in sibling
// portal test files too).
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
// Mutations: approve — via the SAME gate + service as the API
// ---------------------------------------------------------------------------

it('approves an own submitted design through the service', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $design = Design::factory()->for($project)->submitted()->create();

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project])
        ->call('approveDesign', $design->id)
        ->assertHasNoErrors();

    expect($design->refresh()->status)->toBe(DesignStatus::Approved)
        ->and((int) $design->approved_by)->toBe($me->id);
});

it('approves an own submitted RAB and applies the contract effects', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me, ['status' => ProjectStatus::Rab]);
    $rab = Rab::factory()->for($project)->create([
        'status' => RabStatus::Submitted,
        'grand_total' => 500000,
        'version' => 1,
    ]);

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project])
        ->call('approveRab', $rab->id)
        ->assertHasNoErrors();

    expect($rab->refresh()->status)->toBe(RabStatus::Approved)
        ->and($project->refresh()->contract_value)->toEqual('500000.00')  // snapshot from grand_total
        ->and($project->status)->toBe(ProjectStatus::Rab);
});

// ---------------------------------------------------------------------------
// The hard line: a mutation cannot bypass the gate
// ---------------------------------------------------------------------------

it('forbids approving another consumer’s design (no action bypass)', function () {
    $me = portalConsumer();
    $mine = portalOwnedProject($me);
    $theirDesign = Design::factory()->for(portalOwnedProject(portalConsumer()))->submitted()->create();

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $mine])
        ->call('approveDesign', $theirDesign->id)
        ->assertForbidden();

    expect($theirDesign->refresh()->status)->toBe(DesignStatus::Submitted); // unchanged
});

it('forbids approving another consumer’s RAB (no action bypass)', function () {
    $me = portalConsumer();
    $mine = portalOwnedProject($me);
    $theirRab = Rab::factory()->for(portalOwnedProject(portalConsumer()))
        ->create(['status' => RabStatus::Submitted, 'grand_total' => 100000]);

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $mine])
        ->call('approveRab', $theirRab->id)
        ->assertForbidden();

    expect($theirRab->refresh()->status)->toBe(RabStatus::Submitted);
});

// ---------------------------------------------------------------------------
// State guard (not duplicated — policy + service own it) and idempotency
// ---------------------------------------------------------------------------

it('rejects approving a design that is not in submitted state', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $draft = Design::factory()->for($project)->create(); // Draft

    Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project])
        ->call('approveDesign', $draft->id)
        ->assertForbidden();

    expect($draft->refresh()->status)->toBe(DesignStatus::Draft);
});

it('is safe on double approval (a second approve is refused)', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $design = Design::factory()->for($project)->submitted()->create();

    $component = Livewire::actingAs($me)->test(ProjectDetail::class, ['project' => $project]);

    $component->call('approveDesign', $design->id)->assertHasNoErrors();
    expect($design->refresh()->status)->toBe(DesignStatus::Approved);

    // Already approved → no longer submitted → refused (can't double-approve).
    $component->call('approveDesign', $design->id)->assertForbidden();
});

// ---------------------------------------------------------------------------
// RAB PDF — owner only (downloadPdf policy), like the media/API rule
// ---------------------------------------------------------------------------

it('lets the owner download the RAB PDF', function () {
    $me = portalConsumer();
    $rab = Rab::factory()->for(portalOwnedProject($me))->create(['status' => RabStatus::Approved]);

    $this->actingAs($me)
        ->get(route('portal.rabs.pdf', $rab))
        ->assertOk()
        ->assertDownload();
});

it('forbids another consumer from downloading the RAB PDF', function () {
    $rab = Rab::factory()->for(portalOwnedProject(portalConsumer()))->create(['status' => RabStatus::Approved]);

    $this->actingAs(portalConsumer())
        ->get(route('portal.rabs.pdf', $rab))
        ->assertForbidden();
});

it('forbids downloading a draft RAB PDF (not yet a real quote)', function () {
    $me = portalConsumer();
    $rab = Rab::factory()->for(portalOwnedProject($me))->create(['status' => RabStatus::Draft]);

    $this->actingAs($me)
        ->get(route('portal.rabs.pdf', $rab))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Regression: staff cannot reach the portal PDF route
// ---------------------------------------------------------------------------

it('keeps staff out of the portal RAB PDF route', function () {
    $rab = Rab::factory()->for(portalOwnedProject(portalConsumer()))->create(['status' => RabStatus::Approved]);

    $this->actingAs(portalStaff())
        ->get(route('portal.rabs.pdf', $rab))
        ->assertForbidden();
});
