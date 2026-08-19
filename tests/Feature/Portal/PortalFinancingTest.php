<?php

use App\Enums\FinancingDocumentStatus;
use App\Enums\FinancingStatus;
use App\Livewire\Portal\ProjectFinancing;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\MediaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

function portalBank(string $name = 'Bank Mitra'): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', 'mitra_pembiayaan')->value('id'),
        'name' => $name,
    ]);
}

// ---------------------------------------------------------------------------
// 1) Apply — one active per project
// ---------------------------------------------------------------------------

it('applies for financing (submitted) and enforces one active per project', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $bank = portalBank();

    $component = Livewire::actingAs($me)->test(ProjectFinancing::class, ['project' => $project])
        ->set('bank_mitra_id', $bank->id)
        ->set('amount', '50000000')
        ->call('apply')
        ->assertHasNoErrors();

    $financing = Financing::where('project_id', $project->id)->firstOrFail();
    expect($financing->status)->toBe(FinancingStatus::Submitted)
        ->and((int) $financing->konsumen_id)->toBe($me->id);

    // A second active application is refused (model invariant), no duplicate row.
    $component->set('bank_mitra_id', $bank->id)->set('amount', '10000000')->call('apply');
    expect(Financing::where('project_id', $project->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 2) Upload a real binary document → pending
// ---------------------------------------------------------------------------

it('uploads a requirement document (pending) as a real binary', function () {
    Storage::fake('media');

    $me = portalConsumer();
    $project = portalOwnedProject($me);
    Financing::factory()->forProject($project)->forBank(portalBank())->create();

    Livewire::actingAs($me)->test(ProjectFinancing::class, ['project' => $project])
        ->set('docName', 'KTP')
        ->set('file', UploadedFile::fake()->image('ktp.jpg'))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $document = FinancingDocument::firstOrFail();
    expect($document->name)->toBe('KTP')
        ->and($document->status)->toBe(FinancingDocumentStatus::Pending)
        ->and($document->file)->toStartWith('financing-documents/');
    Storage::disk('media')->assertExists($document->file);
});

// ---------------------------------------------------------------------------
// 3) A consumer can never drive the lifecycle (bank-only, §6.5)
// ---------------------------------------------------------------------------

it('denies the consumer any lifecycle / review authority', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    $financing = Financing::factory()->forProject($project)->forBank(portalBank())->create();
    $document = FinancingDocument::factory()->for($financing)->create();

    expect($me->can('manageLifecycle', $financing))->toBeFalse()  // transition / disburse
        ->and($me->can('review', $document))->toBeFalse();        // accept/reject a document
});

// ---------------------------------------------------------------------------
// 4) Sensitive documents: owner may view; another consumer 403 on serve (media-4)
// ---------------------------------------------------------------------------

it('serves a document to its owner but 403s another consumer (signed + policy)', function () {
    Storage::fake('media');

    $me = portalConsumer();
    $project = portalOwnedProject($me);
    Financing::factory()->forProject($project)->forBank(portalBank())->create();

    Livewire::actingAs($me)->test(ProjectFinancing::class, ['project' => $project])
        ->set('docName', 'Slip Gaji')
        ->set('file', UploadedFile::fake()->image('slip.jpg'))
        ->call('uploadDocument');

    $document = FinancingDocument::firstOrFail();
    $url = app(MediaService::class)->temporaryUrl($document);

    $this->actingAs($me)->get($url)->assertOk();                 // owner
    $this->actingAs(portalConsumer())->get($url)->assertForbidden(); // another consumer
});

// ---------------------------------------------------------------------------
// 5) A final financing locks uploads
// ---------------------------------------------------------------------------

it('refuses uploads once the financing is final', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);
    Financing::factory()->forProject($project)->forBank(portalBank())
        ->status(FinancingStatus::Rejected)->create();

    Livewire::actingAs($me)->test(ProjectFinancing::class, ['project' => $project])
        ->set('docName', 'KTP')
        ->set('file', UploadedFile::fake()->image('ktp.jpg'))
        ->call('uploadDocument');

    expect(FinancingDocument::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 6) Ownership: another consumer's financing page → 403; apply gate owner-only
// ---------------------------------------------------------------------------

it('forbids the financing page of another consumer’s project', function () {
    $mine = portalOwnedProject(portalConsumer());
    $theirs = portalOwnedProject(portalConsumer());

    // Owner ok, non-owner forbidden (the page authorizes view on mount).
    $this->actingAs($mine->konsumen)->get(route('portal.projects.financing', $mine))->assertOk();
    $this->actingAs($mine->konsumen)->get(route('portal.projects.financing', $theirs))->assertForbidden();
});

it('gates applyFinancing to the owning consumer', function () {
    $me = portalConsumer();
    $project = portalOwnedProject($me);

    expect($me->can('applyFinancing', $project))->toBeTrue()
        ->and(portalConsumer()->can('applyFinancing', $project))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7) Regression: staff cannot reach the financing page
// ---------------------------------------------------------------------------

it('keeps staff out of the portal financing page', function () {
    $project = portalOwnedProject(portalConsumer());

    $this->actingAs(portalStaff())->get(route('portal.projects.financing', $project))->assertForbidden();
});
