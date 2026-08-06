<?php

use App\Enums\Bidang;
use App\Enums\FinancingStatus;
use App\Models\AuditLog;
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
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    $this->media = app(MediaService::class);
});

function fdUser(string $role): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
}

/**
 * A financing owned by `$konsumen` and banked by `$bank`, plus a stored document.
 *
 * @return array{0: FinancingDocument, 1: User, 2: User} [document, owner konsumen, owning bank]
 */
function fdDocument(MediaService $media): array
{
    $konsumen = fdUser('konsumen');
    $bank = fdUser('mitra_pembiayaan');
    $project = Project::factory()->ownedBy($konsumen)->create();
    $financing = Financing::factory()->forProject($project)->ownedBy($konsumen)->forBank($bank)->create();

    $document = FinancingDocument::factory()->forFinancing($financing)->create([
        'file' => $media->store(new FinancingDocument, UploadedFile::fake()->image('ktp.jpg')),
    ]);

    return [$document, $konsumen, $bank];
}

/** A real minimal PDF upload (server-side MIME guess = application/pdf). */
function fdFakePdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('slip.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

// ---------------------------------------------------------------------------
// Upload — the owning consumer only; image + PDF; server-side type/size
// ---------------------------------------------------------------------------

it('lets the owning consumer upload a KTP image and a payslip PDF', function () {
    $konsumen = fdUser('konsumen');
    $financing = Financing::factory()
        ->forProject(Project::factory()->ownedBy($konsumen)->create())
        ->ownedBy($konsumen)->forBank(fdUser('mitra_pembiayaan'))->create();

    Sanctum::actingAs($konsumen);

    $this->post("/api/v1/financings/{$financing->id}/documents", [
        'name' => 'KTP', 'file' => UploadedFile::fake()->image('ktp.jpg'),
    ])->assertCreated();
    $this->post("/api/v1/financings/{$financing->id}/documents", [
        'name' => 'Slip Gaji', 'file' => fdFakePdf(),
    ])->assertCreated();

    expect($financing->documents()->count())->toBe(2)
        ->and($financing->documents()->get()->every(
            fn (FinancingDocument $d): bool => str_starts_with((string) $d->file, 'financing-documents/')
                && Storage::disk('media')->exists($d->file)
        ))->toBeTrue();
});

it('rejects an unsupported type and an oversize file server-side', function () {
    $konsumen = fdUser('konsumen');
    $financing = Financing::factory()
        ->forProject(Project::factory()->ownedBy($konsumen)->create())
        ->ownedBy($konsumen)->forBank(fdUser('mitra_pembiayaan'))->create();

    Sanctum::actingAs($konsumen);

    $json = ['Accept' => 'application/json']; // so validation errors are 422, not a 302 redirect

    // A video is not an accepted financing-document type.
    $this->post("/api/v1/financings/{$financing->id}/documents", [
        'name' => 'X', 'file' => UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"),
    ], $json)->assertStatus(422);

    // Oversize image (> 5120 KB).
    $this->post("/api/v1/financings/{$financing->id}/documents", [
        'name' => 'X', 'file' => UploadedFile::fake()->image('huge.jpg')->size(6000),
    ], $json)->assertStatus(422);

    expect(FinancingDocument::count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// SERVE — the strict leak matrix (signature valid throughout; policy decides)
// ---------------------------------------------------------------------------

it('serves the document to the owning consumer, owning bank and overseers ONLY', function () {
    [$document, $konsumen, $bank] = fdDocument($this->media);
    $url = $this->media->temporaryUrl($document); // one valid, fresh, signed URL

    // Allowed.
    $this->actingAs($konsumen)->get($url)->assertOk();
    $this->actingAs($bank)->get($url)->assertOk();
    $this->actingAs(fdUser('owner'))->get($url)->assertOk();
    $this->actingAs(fdUser('direktur'))->get($url)->assertOk();

    // Denied — a VALID signature is not enough; the policy still refuses (403).
    $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id'), 'bidang' => Bidang::Cufid]);
    foreach ([$manager, fdUser('finance'), fdUser('mitra_pembiayaan'), fdUser('konsumen'), fdUser('hr')] as $outsider) {
        $this->actingAs($outsider)->get($url)->assertForbidden("{$outsider->role->name} must not read the document");
    }
});

it('refuses a guest, an unsigned URL, and a tampered/expired signature', function () {
    [$document] = fdDocument($this->media);
    $url = $this->media->temporaryUrl($document);

    // Guest (no auth) — even with a valid signature.
    $this->getJson($url)->assertUnauthorized();

    // Naked/guessed URL (no signature) — refused before any policy check.
    $this->actingAs(fdUser('owner'))
        ->get(route('media.show', ['type' => 'financing_document', 'id' => $document->id]))
        ->assertForbidden();

    // Expired signature.
    $this->actingAs(fdUser('owner'));
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

// ---------------------------------------------------------------------------
// Audit — the sensitive file pointer is redacted in the trail (4-3)
// ---------------------------------------------------------------------------

it('redacts the file pointer in the audit trail on upload', function () {
    [$document] = fdDocument($this->media);

    $audit = AuditLog::where('entity', FinancingDocument::class)
        ->where('entity_id', $document->id)->where('action', 'created')->sole();

    // The stored key must appear NOWHERE in the audit payload; file is redacted.
    expect(($audit->after['file'] ?? null))->toBe('[redacted]')
        ->and(json_encode($audit->after))->not->toContain((string) $document->file)
        ->and(json_encode($audit->after))->not->toContain('financing-documents/');
});

// ---------------------------------------------------------------------------
// Immutability — a final financing accepts no document upload
// ---------------------------------------------------------------------------

it('refuses uploading a document to a final financing', function () {
    $konsumen = fdUser('konsumen');
    $financing = Financing::factory()
        ->forProject(Project::factory()->ownedBy($konsumen)->create())
        ->ownedBy($konsumen)->forBank(fdUser('mitra_pembiayaan'))
        ->status(FinancingStatus::Disbursed)->create();

    Sanctum::actingAs($konsumen);
    $this->post("/api/v1/financings/{$financing->id}/documents", [
        'name' => 'KTP', 'file' => UploadedFile::fake()->image('ktp.jpg'),
    ], ['Accept' => 'application/json'])->assertStatus(422);

    expect(FinancingDocument::count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBeEmpty(); // no orphan file
});
