<?php

use App\Enums\DesignStatus;
use App\Exceptions\DesignException;
use App\Models\Design;
use App\Models\Project;
use App\Models\User;
use App\Services\DesignService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = new DesignService;
});

function konsumenFor(Project $project): User
{
    return User::find($project->konsumen_id);
}

// ---------------------------------------------------------------------------
// Versioning
// ---------------------------------------------------------------------------

it('auto-increments design versions per project', function () {
    $project = Project::factory()->create();

    $v1 = $this->service->addVersion($project, ['file' => 'a.pdf']);
    $v2 = $this->service->addVersion($project, ['file' => 'b.pdf']);
    $v3 = $this->service->addVersion($project);

    expect($v1->version)->toBe(1)
        ->and($v2->version)->toBe(2)
        ->and($v3->version)->toBe(3)
        ->and($v1->status)->toBe(DesignStatus::Draft);

    // Versions are per-project, so another project starts again at 1.
    $other = Project::factory()->create();
    expect($this->service->addVersion($other)->version)->toBe(1);
});

// ---------------------------------------------------------------------------
// Submit — only a draft can be submitted
// ---------------------------------------------------------------------------

it('submits a draft design', function () {
    $design = Design::factory()->create();

    $this->service->submit($design);

    expect($design->refresh()->status)->toBe(DesignStatus::Submitted);
});

it('refuses to submit a design that is not a draft', function () {
    $submitted = Design::factory()->submitted()->create();

    expect(fn () => $this->service->submit($submitted))->toThrow(DesignException::class);
});

// ---------------------------------------------------------------------------
// Approve — only a submitted version, records who/when, no project status change
// ---------------------------------------------------------------------------

it('approves a submitted design and records who/when without advancing the project', function () {
    $project = Project::factory()->create();
    $konsumen = konsumenFor($project);
    $design = Design::factory()->for($project)->submitted()->create();
    $statusBefore = $project->status;

    $this->service->approve($design, $konsumen);

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Approved)
        ->and($design->approved_by)->toBe($konsumen->id)
        ->and($design->approved_at)->not->toBeNull()
        // the main project status is deliberately untouched (advances at 2B-5)
        ->and($project->refresh()->status)->toBe($statusBefore);
});

it('refuses to approve a design that is not submitted', function () {
    $project = Project::factory()->create();
    $draft = Design::factory()->for($project)->create();

    expect(fn () => $this->service->approve($draft, konsumenFor($project)))
        ->toThrow(DesignException::class);
});
