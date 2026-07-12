<?php

use App\Enums\Bidang;
use App\Enums\FinancingStatus;
use App\Exceptions\FinancingException;
use App\Models\Financing;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function finRoled(string $name, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id'), 'bidang' => $bidang]);
}

// ---------------------------------------------------------------------------
// Schema & relations
// ---------------------------------------------------------------------------

it('links a financing to its project, consumer and bank', function () {
    $konsumen = finRoled('konsumen');
    $bank = finRoled('mitra_pembiayaan');
    $project = Project::factory()->ownedBy($konsumen)->create();

    $financing = Financing::factory()->forProject($project)->forBank($bank)->create(['amount' => '50000000.00']);

    expect($financing->project->is($project))->toBeTrue()
        ->and((int) $financing->konsumen_id)->toBe($konsumen->id)
        ->and($financing->bankMitra->is($bank))->toBeTrue()
        ->and($financing->status)->toBe(FinancingStatus::Submitted)
        ->and($financing->amount)->toBe('50000000.00');
});

// ---------------------------------------------------------------------------
// One active financing per project
// ---------------------------------------------------------------------------

it('allows only one active financing per project', function () {
    $project = Project::factory()->create();
    Financing::factory()->forProject($project)->create(); // active (submitted)

    expect(fn () => Financing::factory()->forProject($project)->create())
        ->toThrow(FinancingException::class);
});

it('permits a new application once the previous one is final', function () {
    $project = Project::factory()->create();
    Financing::factory()->forProject($project)->status(FinancingStatus::Rejected)->create();

    // A rejected application is final, so a fresh one is allowed.
    $fresh = Financing::factory()->forProject($project)->create();

    expect($fresh->status)->toBe(FinancingStatus::Submitted);
});

// ---------------------------------------------------------------------------
// Transition invariant + status log trail
// ---------------------------------------------------------------------------

it('walks legal transitions and logs each one', function () {
    $by = finRoled('direktur');
    $financing = Financing::factory()->create(); // submitted

    $financing->transitionTo(FinancingStatus::Interview, $by->id, 'panggil wawancara');
    $financing->transitionTo(FinancingStatus::Approved, $by->id);
    $financing->transitionTo(FinancingStatus::Disbursed, $by->id, 'dana cair');

    expect($financing->fresh()->status)->toBe(FinancingStatus::Disbursed)
        ->and($financing->statusLogs()->count())->toBe(3)
        ->and($financing->statusLogs()->pluck('status')->all())
        ->toBe([FinancingStatus::Interview, FinancingStatus::Approved, FinancingStatus::Disbursed]);
});

it('allows bouncing between interview and docs_required', function () {
    // After an interview the bank can still ask for more documents (4-4 fix).
    $financing = Financing::factory()->status(FinancingStatus::Interview)->create();

    $financing->transitionTo(FinancingStatus::DocsRequired);
    expect($financing->fresh()->status)->toBe(FinancingStatus::DocsRequired);

    $financing->transitionTo(FinancingStatus::Interview);
    expect($financing->fresh()->status)->toBe(FinancingStatus::Interview)
        ->and($financing->statusLogs()->count())->toBe(2);
});

it('rejects an illegal jump and any move out of a final state', function () {
    $submitted = Financing::factory()->create();
    expect(fn () => $submitted->transitionTo(FinancingStatus::Disbursed))->toThrow(FinancingException::class);
    expect($submitted->fresh()->status)->toBe(FinancingStatus::Submitted)
        ->and($submitted->statusLogs()->count())->toBe(0);

    $disbursed = Financing::factory()->status(FinancingStatus::Disbursed)->create();
    expect(fn () => $disbursed->transitionTo(FinancingStatus::Approved))->toThrow(FinancingException::class);

    $rejected = Financing::factory()->status(FinancingStatus::Rejected)->create();
    expect(fn () => $rejected->transitionTo(FinancingStatus::Interview))->toThrow(FinancingException::class);
});

// ---------------------------------------------------------------------------
// Scope — a bank sees only its own financings
// ---------------------------------------------------------------------------

it('scopes financing visibility to the owning bank', function () {
    $bankA = finRoled('mitra_pembiayaan');
    $bankB = finRoled('mitra_pembiayaan');
    $fa = Financing::factory()->forBank($bankA)->create();
    $fb = Financing::factory()->forBank($bankB)->create();

    $this->actingAs($bankA);

    expect(Financing::pluck('id')->all())->toBe([$fa->id]); // BankMitraScope

    expect($bankA->can('view', $fa))->toBeTrue()
        ->and($bankA->can('view', $fb))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The two-scope separation (keputusan B): bank writes its financing, NOT projects
// ---------------------------------------------------------------------------

it('lets the bank manage its own financing lifecycle but never touch projects', function () {
    $bankA = finRoled('mitra_pembiayaan');
    $bankB = finRoled('mitra_pembiayaan');
    $fa = Financing::factory()->forBank($bankA)->create();
    $fb = Financing::factory()->forBank($bankB)->create();

    // Writes its own financing, not another bank's.
    expect($bankA->can('manageLifecycle', $fa))->toBeTrue()
        ->and($bankA->can('manageLifecycle', $fb))->toBeFalse();

    // §6.5 stays intact: the bank is read-only on the project — every project
    // mutation is denied, so the financing write grant cannot leak.
    expect($bankA->can('update', $fa->project))->toBeFalse()
        ->and($bankA->can('delete', $fa->project))->toBeFalse()
        ->and($bankA->can('create', Project::class))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Consumer + staff authorization
// ---------------------------------------------------------------------------

it('authorizes viewing/applying for the consumer and managing for overseers', function () {
    $konsumen = finRoled('konsumen');
    $other = finRoled('konsumen');
    $project = Project::factory()->ownedBy($konsumen)->inBidang(Bidang::Cufid)->create();
    $financing = Financing::factory()->forProject($project)->create();

    // Consumer sees/owns its own, cannot manage the lifecycle.
    expect($konsumen->can('view', $financing))->toBeTrue()
        ->and($other->can('view', $financing))->toBeFalse()
        ->and($konsumen->can('manageLifecycle', $financing))->toBeFalse();

    // Apply gate — owning consumer only.
    expect($konsumen->can('applyFinancing', $project))->toBeTrue()
        ->and($other->can('applyFinancing', $project))->toBeFalse();

    // Overseer manages; Manager sees its bidang only, never manages.
    expect(finRoled('direktur')->can('manageLifecycle', $financing))->toBeTrue()
        ->and(finRoled('manager', Bidang::Cufid)->can('view', $financing))->toBeTrue()
        ->and(finRoled('manager', Bidang::Cc)->can('view', $financing))->toBeFalse()
        ->and(finRoled('manager', Bidang::Cufid)->can('manageLifecycle', $financing))->toBeFalse();
});
