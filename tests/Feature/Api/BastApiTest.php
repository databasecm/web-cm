<?php

use App\Enums\BastParty;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentScheme;
use App\Enums\ProjectStatus;
use App\Models\AuditLog;
use App\Models\Bast;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\BastService;
use App\Services\CheckoutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function bastKonsumen(): User
{
    return User::factory()->create(['role_id' => Role::where('name', 'konsumen')->value('id')]);
}

function bastActiveProject(User $konsumen, PaymentScheme $scheme = PaymentScheme::Termin3): Project
{
    $project = Project::factory()->ownedBy($konsumen)->status(ProjectStatus::Rab)->create(['contract_value' => '1000000.00']);
    (new CheckoutService)->checkout($project, $scheme);

    return $project->refresh();
}

// ---------------------------------------------------------------------------
// GET /projects/{id}/bast — own project only
// ---------------------------------------------------------------------------

it('shows the BAST of an own project and 404s before it is issued', function () {
    $me = bastKonsumen();
    $project = bastActiveProject($me);

    Sanctum::actingAs($me);

    // Not issued yet.
    $this->getJson("/api/v1/projects/{$project->id}/bast")->assertNotFound();

    app(BastService::class)->issue($project);

    $this->getJson("/api/v1/projects/{$project->id}/bast")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.signed_customer', false);
});

it('forbids viewing another consumer BAST', function () {
    $me = bastKonsumen();
    $theirs = bastActiveProject(bastKonsumen());
    app(BastService::class)->issue($theirs);

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/projects/{$theirs->id}/bast")->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /bast/{id}/sign — owning consumer records signed_customer
// ---------------------------------------------------------------------------

it('records the consumer signature and fills signed_customer_by', function () {
    $me = bastKonsumen();
    $project = bastActiveProject($me);
    $bast = app(BastService::class)->issue($project);

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/bast/{$bast->id}/sign")
        ->assertOk()
        ->assertJsonPath('data.signed_customer', true)
        ->assertJsonPath('meta.message', 'Tanda tangan konsumen direkam.');

    $bast->refresh();
    expect($bast->signed_customer)->toBeTrue()
        ->and((int) $bast->signed_customer_by)->toBe($me->id)
        ->and($bast->isSigned())->toBeFalse(); // company not signed yet

    // The signature mutation is audited.
    $audit = AuditLog::where('entity', Bast::class)->where('entity_id', $bast->id)
        ->where('action', 'updated')->latest('id')->first();
    expect($audit)->not->toBeNull();
});

it('forbids a non-owner consumer from signing', function () {
    $me = bastKonsumen();
    $theirs = bastActiveProject(bastKonsumen());
    $bast = app(BastService::class)->issue($theirs);

    Sanctum::actingAs($me);

    $this->postJson("/api/v1/bast/{$bast->id}/sign")->assertForbidden();
    expect($bast->refresh()->signed_customer)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Combined path (company via service/UI + customer via API) → signed → unlock
// ---------------------------------------------------------------------------

it('unlocks the pelunasan when company (staff) and customer (API) both sign', function () {
    $me = bastKonsumen();
    $project = bastActiveProject($me);
    $bast = app(BastService::class)->issue($project);

    // Company signs (as the Filament action does).
    app(BastService::class)->recordSignature($bast, BastParty::Company, User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
    ])->id);

    $pelunasan = $project->installments()->where('due_condition', 'bast')->sole();
    expect($pelunasan->status)->toBe(InstallmentStatus::Locked);

    // Customer signs via the API → BAST signed → pelunasan unlocks.
    Sanctum::actingAs($me);
    $this->postJson("/api/v1/bast/{$bast->id}/sign")
        ->assertOk()
        ->assertJsonPath('data.status', 'signed');

    expect($pelunasan->refresh()->status)->toBe(InstallmentStatus::Unlocked);

    // Double submit does not double-unlock.
    $this->postJson("/api/v1/bast/{$bast->id}/sign")->assertOk();
    expect($project->installments()->where('due_condition', 'bast')->where('status', 'unlocked')->count())->toBe(1);
});
