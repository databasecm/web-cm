<?php

use App\Enums\DesignStatus;
use App\Enums\ProjectStatus;
use App\Enums\RabStatus;
use App\Models\Design;
use App\Models\Project;
use App\Models\Rab;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function apiKonsumen(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
}

function ownedProject(User $konsumen): Project
{
    return Project::factory()->ownedBy($konsumen)->create();
}

// ---------------------------------------------------------------------------
// Channel guard
// ---------------------------------------------------------------------------

it('rejects unauthenticated and non-consumer access to the channel', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();

    $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    Sanctum::actingAs($manager);
    $this->getJson('/api/v1/projects')->assertForbidden();
});

// ---------------------------------------------------------------------------
// Projects — own only
// ---------------------------------------------------------------------------

it('lists only the consumer own projects', function () {
    $me = apiKonsumen();
    ownedProject($me);
    ownedProject($me);
    ownedProject(apiKonsumen()); // someone else's

    Sanctum::actingAs($me);

    $this->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.count', 2);
});

it('shows own project but 403s another consumer project', function () {
    $me = apiKonsumen();
    $mine = ownedProject($me);
    $theirs = ownedProject(apiKonsumen());

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/projects/{$mine->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $mine->id);

    $this->getJson("/api/v1/projects/{$theirs->id}")->assertForbidden();
    $this->getJson('/api/v1/projects/999999')->assertNotFound();
});

it('returns designs, rabs and installments for an own project only', function () {
    $me = apiKonsumen();
    $mine = ownedProject($me);
    Design::factory()->for($mine)->create();
    Rab::factory()->for($mine)->create();

    $theirs = ownedProject(apiKonsumen());

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/projects/{$mine->id}/designs")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/projects/{$mine->id}/rabs")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/projects/{$mine->id}/installments")->assertOk()->assertJsonPath('meta.count', 0);

    $this->getJson("/api/v1/projects/{$theirs->id}/designs")->assertForbidden();
});

// ---------------------------------------------------------------------------
// Design approval — own + submitted only
// ---------------------------------------------------------------------------

it('approves an own submitted design and rejects others', function () {
    $me = apiKonsumen();
    $project = ownedProject($me);
    $submitted = Design::factory()->for($project)->submitted()->create();
    $draft = Design::factory()->for($project)->version(2)->create();

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/designs/{$submitted->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', DesignStatus::Approved->value);
    expect($submitted->refresh()->approved_by)->toBe($me->id);

    // a draft (not submitted) cannot be approved
    $this->postJson("/api/v1/designs/{$draft->id}/approve")->assertForbidden();

    // another consumer cannot approve my design
    $other = apiKonsumen();
    $otherSubmitted = Design::factory()->for(ownedProject($other))->submitted()->create();
    $this->postJson("/api/v1/designs/{$otherSubmitted->id}/approve")->assertForbidden();
});

// ---------------------------------------------------------------------------
// RAB approval — finalises the contract (2B-5)
// ---------------------------------------------------------------------------

it('approves an own submitted RAB and fills the project contract value', function () {
    $me = apiKonsumen();
    $project = ownedProject($me);
    $rab = Rab::factory()->for($project)->status(RabStatus::Submitted)->create(['grand_total' => '500000.00']);

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/rabs/{$rab->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', RabStatus::Approved->value);

    expect($project->refresh()->contract_value)->toBe('500000.00')
        ->and($project->status)->toBe(ProjectStatus::Rab);
});

// ---------------------------------------------------------------------------
// Checkout — needs an approved RAB, no double checkout
// ---------------------------------------------------------------------------

it('checks out an own project and generates the installment schedule', function () {
    $me = apiKonsumen();
    $project = Project::factory()->ownedBy($me)->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/projects/{$project->id}/checkout", ['payment_scheme' => 'termin3'])
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.payment_scheme', 'termin3');

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});

it('rejects checkout without an approved RAB and a double checkout', function () {
    $me = apiKonsumen();

    // no contract value yet → 422
    $noContract = ownedProject($me);
    Sanctum::actingAs($me);
    $this->postJson("/api/v1/projects/{$noContract->id}/checkout", ['payment_scheme' => 'lunas'])
        ->assertStatus(422);

    // invalid scheme → 422 validation
    $contracted = Project::factory()->ownedBy($me)->status(ProjectStatus::Rab)->create(['contract_value' => '100.00']);
    $this->postJson("/api/v1/projects/{$contracted->id}/checkout", ['payment_scheme' => 'bogus'])
        ->assertStatus(422)->assertJsonValidationErrors('payment_scheme');

    // first checkout ok, second rejected
    $this->postJson("/api/v1/projects/{$contracted->id}/checkout", ['payment_scheme' => 'lunas'])->assertOk();
    $this->postJson("/api/v1/projects/{$contracted->id}/checkout", ['payment_scheme' => 'fifty'])->assertStatus(422);

    // another consumer cannot check out my project
    $other = apiKonsumen();
    Sanctum::actingAs($other);
    $this->postJson("/api/v1/projects/{$contracted->id}/checkout", ['payment_scheme' => 'lunas'])->assertForbidden();
});
