<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Enums\ProjectStatus;
use App\Exceptions\ProjectConversionException;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectFromDealService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->service = new ProjectFromDealService;
});

function dealManager(Bidang $bidang = Bidang::Cufid): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => $bidang,
    ]);
}

function konsumenAccount(): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
    ]);
}

/** A consultation that is a deal with a registered consumer. */
function dealConsultation(User $konsumen, User $manager, Bidang $bidang = Bidang::Cufid): Consultation
{
    return Consultation::factory()
        ->ownedBy($konsumen)
        ->claimedBy($manager)
        ->inBidang($bidang)
        ->status(ConsultationStatus::Deal)
        ->create();
}

// ---------------------------------------------------------------------------
// Happy path — draft project created and correctly linked
// ---------------------------------------------------------------------------

it('creates a draft project from a deal, deriving consumer/manager/bidang', function () {
    $konsumen = konsumenAccount();
    $manager = dealManager(Bidang::Cufid);
    $consultation = dealConsultation($konsumen, $manager, Bidang::Cufid);
    $this->actingAs($manager);

    $project = $this->service->create($consultation, $manager, 'Renovasi Dapur');

    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->konsumen_id)->toBe($konsumen->id)
        ->and($project->manager_id)->toBe($manager->id)
        ->and($project->bidang)->toBe(Bidang::Cufid)
        ->and($project->consultation_id)->toBe($consultation->id)
        ->and($project->title)->toBe('Renovasi Dapur')
        ->and($project->contract_value)->toBeNull();
});

it('falls back to an automatic title when none is given', function () {
    $konsumen = konsumenAccount();
    $manager = dealManager();
    $consultation = dealConsultation($konsumen, $manager);
    $this->actingAs($manager);

    $project = $this->service->create($consultation, $manager);

    expect($project->title)->toContain($konsumen->name);
});

it('audits the project creation against the actor', function () {
    $konsumen = konsumenAccount();
    $manager = dealManager();
    $consultation = dealConsultation($konsumen, $manager);
    $this->actingAs($manager);

    $project = $this->service->create($consultation, $manager);

    $audit = AuditLog::where('entity', Project::class)
        ->where('entity_id', $project->id)
        ->where('action', 'created')
        ->sole();

    expect($audit->user_id)->toBe($manager->id);
});

// ---------------------------------------------------------------------------
// State guards — only valid in the deal context
// ---------------------------------------------------------------------------

it('refuses a consultation that is not a deal', function () {
    $manager = dealManager();
    $open = Consultation::factory()->ownedBy(konsumenAccount())->inBidang(Bidang::Cufid)
        ->status(ConsultationStatus::Open)->create();
    $this->actingAs($manager);

    expect(fn () => $this->service->create($open, $manager))
        ->toThrow(ProjectConversionException::class);
    expect(Project::count())->toBe(0);
});

it('refuses a deal without a consumer account', function () {
    $manager = dealManager();
    $guestDeal = Consultation::factory()->inBidang(Bidang::Cufid)
        ->status(ConsultationStatus::Deal)->create(['konsumen_id' => null]);
    $this->actingAs($manager);

    expect(fn () => $this->service->create($guestDeal, $manager))
        ->toThrow(ProjectConversionException::class);
});

it('enforces one project per deal', function () {
    $konsumen = konsumenAccount();
    $manager = dealManager();
    $consultation = dealConsultation($konsumen, $manager);
    $this->actingAs($manager);

    $this->service->create($consultation, $manager);

    expect(fn () => $this->service->create($consultation, $manager))
        ->toThrow(ProjectConversionException::class);
    expect(Project::where('consultation_id', $consultation->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Gate — narrow, bidang-scoped, deal-bound
// ---------------------------------------------------------------------------

it('scopes the createProjectForDeal gate to staff and bidang', function () {
    expect(Gate::forUser(dealManager(Bidang::Cufid))->allows('createProjectForDeal', Bidang::Cufid->value))->toBeTrue()
        ->and(Gate::forUser(dealManager(Bidang::Cufid))->allows('createProjectForDeal', Bidang::Cc->value))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create(['role_id' => Role::where('name', 'direktur')->value('id')]))
            ->allows('createProjectForDeal', Bidang::Cc->value))->toBeTrue();

    foreach (['finance', 'hr', 'mitra_pembiayaan', 'mandor', 'konsumen'] as $roleName) {
        $actor = User::factory()->create([
            'role_id' => Role::where('name', $roleName)->value('id'),
            'bidang' => $roleName === 'mandor' ? Bidang::Cufid : null,
        ]);
        expect(Gate::forUser($actor)->allows('createProjectForDeal', Bidang::Cufid->value))
            ->toBeFalse("{$roleName} must not create a project for a deal");
    }
});

// ---------------------------------------------------------------------------
// HARD INVARIANT — the bridge never widens the Manager's general rights
// ---------------------------------------------------------------------------

it('does not widen the Manager account-management rights (ADR-0001 holds)', function () {
    $konsumen = konsumenAccount();
    $manager = dealManager(Bidang::Cufid);
    $consultation = dealConsultation($konsumen, $manager, Bidang::Cufid);
    $this->actingAs($manager);

    $this->service->create($consultation, $manager);

    // Creating a project for the deal grants no account rights over the consumer.
    expect($manager->can('view', $konsumen))->toBeFalse()
        ->and($manager->can('update', $konsumen))->toBeFalse()
        ->and($manager->can('delete', $konsumen))->toBeFalse()
        // and the general account-management capability is unchanged
        ->and($manager->canManageAccounts())->toBeTrue(); // its normal value, not widened
});
