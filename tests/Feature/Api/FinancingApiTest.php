<?php

use App\Enums\FinancingStatus;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function famKons(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
}

function famBank(string $name = 'Bank Mitra'): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'mitra_pembiayaan')->value('id'), 'name' => $name]);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------

it('lets the owning consumer apply and enforces one active per project', function () {
    $me = famKons();
    $bank = famBank();
    $project = Project::factory()->ownedBy($me)->create();

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/projects/{$project->id}/financing", [
        'bank_mitra_id' => $bank->id,
        'amount' => '75000000',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.amount', '75000000.00')
        ->assertJsonPath('meta.message', 'Pengajuan pembiayaan dibuat.');

    // Second active application on the same project is refused (4-1 invariant).
    $this->postJson("/api/v1/projects/{$project->id}/financing", [
        'bank_mitra_id' => $bank->id,
        'amount' => '10000000',
    ])->assertStatus(422);
});

it('rejects applying for another consumer project and an invalid bank', function () {
    $me = famKons();
    $theirs = Project::factory()->ownedBy(famKons())->create();
    $mine = Project::factory()->ownedBy($me)->create();

    Sanctum::actingAs($me);

    // Not my project.
    $this->postJson("/api/v1/projects/{$theirs->id}/financing", [
        'bank_mitra_id' => famBank()->id,
        'amount' => '1000000',
    ])->assertForbidden();

    // bank_mitra_id must be an actual financing bank (a consumer id is invalid).
    $this->postJson("/api/v1/projects/{$mine->id}/financing", [
        'bank_mitra_id' => famKons()->id,
        'amount' => '1000000',
    ])->assertStatus(422)->assertJsonValidationErrors('bank_mitra_id');
});

// ---------------------------------------------------------------------------
// View — own only, with status history
// ---------------------------------------------------------------------------

it('shows an own financing with its status history but 403s another consumer', function () {
    $me = famKons();
    $project = Project::factory()->ownedBy($me)->create();
    $financing = Financing::factory()->forProject($project)->forBank(famBank())->create();
    $financing->transitionTo(FinancingStatus::Interview, null, 'jadwal wawancara');

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/financings/{$financing->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $financing->id)
        ->assertJsonPath('data.status', 'interview')
        ->assertJsonCount(1, 'data.status_logs'); // only transitions are logged

    Sanctum::actingAs(famKons());
    $this->getJson("/api/v1/financings/{$financing->id}")->assertForbidden();
});

it('returns the project financing or 404 before one exists', function () {
    $me = famKons();
    $project = Project::factory()->ownedBy($me)->create();

    Sanctum::actingAs($me);
    $this->getJson("/api/v1/projects/{$project->id}/financing")->assertNotFound();

    Financing::factory()->forProject($project)->forBank(famBank())->create();
    $this->getJson("/api/v1/projects/{$project->id}/financing")
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');
});

// ---------------------------------------------------------------------------
// Documents — upload own, list own; final locks; others rejected
// ---------------------------------------------------------------------------

it('uploads and lists documents on an own financing', function () {
    $me = famKons();
    $project = Project::factory()->ownedBy($me)->create();
    $financing = Financing::factory()->forProject($project)->forBank(famBank())->create();

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/financings/{$financing->id}/documents", [
        'name' => 'KTP',
        'file' => 'financing/ktp.pdf',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.name', 'KTP');

    $this->getJson("/api/v1/financings/{$financing->id}/documents")
        ->assertOk()
        ->assertJsonPath('meta.count', 1);
});

it('forbids uploading to another consumer financing and blocks a final one', function () {
    $me = famKons();
    $theirs = Financing::factory()->forProject(Project::factory()->ownedBy(famKons())->create())->forBank(famBank())->create();

    Sanctum::actingAs($me);
    $this->postJson("/api/v1/financings/{$theirs->id}/documents", ['name' => 'KTP'])->assertForbidden();

    // My own, but final (disbursed) → documents locked (422).
    $mineFinal = Financing::factory()->forProject(Project::factory()->ownedBy($me)->create())->forBank(famBank())
        ->status(FinancingStatus::Disbursed)->create();
    $this->postJson("/api/v1/financings/{$mineFinal->id}/documents", ['name' => 'KTP'])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Consumers can never drive the lifecycle (bank-only)
// ---------------------------------------------------------------------------

it('never lets a consumer manage the lifecycle or review documents', function () {
    $me = famKons();
    $financing = Financing::factory()->forProject(Project::factory()->ownedBy($me)->create())->forBank(famBank())->create();
    $doc = FinancingDocument::factory()->forFinancing($financing)->create();

    expect($me->can('manageLifecycle', $financing))->toBeFalse()
        ->and($me->can('review', $doc))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Banks list — public name only
// ---------------------------------------------------------------------------

it('lists selectable banks with id and name only', function () {
    famBank('BSI');
    famBank('Muamalat');

    Sanctum::actingAs(famKons());

    $response = $this->getJson('/api/v1/banks')->assertOk()->assertJsonPath('meta.count', 2);

    // Only id + name are exposed — no internal fields.
    $first = $response->json('data.0');
    expect(array_keys($first))->toBe(['id', 'name']);
});
