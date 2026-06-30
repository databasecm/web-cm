<?php

use App\Enums\ProjectStatus;
use App\Enums\RabStatus;
use App\Exceptions\RabException;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Rab;
use App\Models\User;
use App\Services\RabService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = new RabService;
});

it('submits a draft RAB', function () {
    $rab = Rab::factory()->create();

    $this->service->submit($rab);

    expect($rab->refresh()->status)->toBe(RabStatus::Submitted);
});

it('refuses to submit a RAB that is not a draft', function () {
    $rab = Rab::factory()->status(RabStatus::Submitted)->create();

    expect(fn () => $this->service->submit($rab))->toThrow(RabException::class);
});

// ---------------------------------------------------------------------------
// Approval finalises: contract_value snapshot + status advance (single control point)
// ---------------------------------------------------------------------------

it('snapshots contract_value and advances the project when a RAB is approved', function () {
    $project = Project::factory()->status(ProjectStatus::Design)->create();
    $konsumen = User::find($project->konsumen_id);
    $rab = Rab::factory()->for($project)->status(RabStatus::Submitted)->create(['grand_total' => '371794.50']);

    $this->actingAs($konsumen);
    $this->service->approve($rab, $konsumen);

    $project->refresh();
    expect($rab->refresh()->status)->toBe(RabStatus::Approved)
        ->and($project->contract_value)->toBe('371794.50')
        ->and($project->status)->toBe(ProjectStatus::Rab);

    // The contract_value change is audited on the project.
    $audit = AuditLog::where('entity', Project::class)->where('entity_id', $project->id)
        ->where('action', 'updated')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->after)->toHaveKey('contract_value');
});

it('refuses to approve a RAB that is not submitted', function () {
    $rab = Rab::factory()->create(); // draft
    $konsumen = User::find($rab->project->konsumen_id);

    expect(fn () => $this->service->approve($rab, $konsumen))->toThrow(RabException::class);
});

// ---------------------------------------------------------------------------
// One contract: a new approved revision supersedes the previous one (recorded)
// ---------------------------------------------------------------------------

it('supersedes a previously approved RAB when a new revision is approved', function () {
    $project = Project::factory()->create();
    $konsumen = User::find($project->konsumen_id);
    $this->actingAs($konsumen);

    $v1 = Rab::factory()->for($project)->status(RabStatus::Submitted)->create(['version' => 1, 'grand_total' => '100000.00']);
    $this->service->approve($v1, $konsumen);

    $v2 = Rab::factory()->for($project)->status(RabStatus::Submitted)->create(['version' => 2, 'grand_total' => '150000.00']);
    $this->service->approve($v2, $konsumen);

    expect($v1->refresh()->status)->toBe(RabStatus::Superseded)
        ->and($v2->refresh()->status)->toBe(RabStatus::Approved)
        // the latest approved RAB is the contract
        ->and($project->refresh()->contract_value)->toBe('150000.00');
});
