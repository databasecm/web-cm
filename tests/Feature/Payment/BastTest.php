<?php

use App\Enums\BastStatus;
use App\Exceptions\BastException;
use App\Models\Bast;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Schema — one BAST per project (1—1) with an FK to projects
// ---------------------------------------------------------------------------

it('links a BAST to its project one-to-one', function () {
    $project = Project::factory()->create();
    $bast = Bast::factory()->for($project)->create();

    expect($bast->project->is($project))->toBeTrue()
        ->and($project->bast->is($bast))->toBeTrue()
        ->and($bast->status)->toBe(BastStatus::Draft)
        ->and($bast->signed_customer)->toBeFalse()
        ->and($bast->signed_company)->toBeFalse();
});

it('forbids a second BAST for the same project (unique project_id)', function () {
    $project = Project::factory()->create();
    Bast::factory()->for($project)->create();

    expect(fn () => Bast::factory()->for($project)->create())
        ->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// Invariant — `signed` requires BOTH signatures, on every write path
// ---------------------------------------------------------------------------

it('refuses to persist signed without both signatures', function () {
    $project = Project::factory()->create();

    // Direct mass-assign create: signed but no signatures.
    expect(fn () => Bast::create([
        'project_id' => $project->id,
        'status' => BastStatus::Signed,
    ]))->toThrow(BastException::class);

    // Only the customer signed → still cannot be signed.
    $bast = Bast::factory()->for($project)->signedByCustomer()->create();
    $bast->status = BastStatus::Signed;
    expect(fn () => $bast->save())->toThrow(BastException::class);

    // markSigned() enforces the same rule.
    expect(fn () => $bast->markSigned())->toThrow(BastException::class);

    // The database still holds the untouched draft.
    expect($bast->fresh()->status)->toBe(BastStatus::Draft);
});

// ---------------------------------------------------------------------------
// Transition — both signatures present → signed, signed_at stamped
// ---------------------------------------------------------------------------

it('stamps signed_at when it becomes signed', function () {
    $project = Project::factory()->create();
    $bast = Bast::factory()->for($project)->signedByCustomer()->signedByCompany()->create();

    expect($bast->status)->toBe(BastStatus::Draft)
        ->and($bast->signed_at)->toBeNull();

    $bast->markSigned();

    expect($bast->status)->toBe(BastStatus::Signed)
        ->and($bast->signed_at)->not->toBeNull()
        ->and($bast->fresh()->status)->toBe(BastStatus::Signed);
});

it('auto-stamps signed_at for a factory-signed BAST', function () {
    $bast = Bast::factory()->signed()->create();

    expect($bast->status)->toBe(BastStatus::Signed)
        ->and($bast->bothPartiesSigned())->toBeTrue()
        ->and($bast->signed_at)->not->toBeNull();
});

it('keeps a draft untouched even when both parties have signed', function () {
    // Recording both signatures does NOT auto-advance the status; that is an
    // explicit transition (markSigned / BastService in 3-2).
    $bast = Bast::factory()->signedByCustomer()->signedByCompany()->create();

    expect($bast->status)->toBe(BastStatus::Draft)
        ->and($bast->signed_at)->toBeNull();
});
