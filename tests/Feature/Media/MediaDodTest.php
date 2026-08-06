<?php

/*
|--------------------------------------------------------------------------
| Media Specification (living documentation) — ADR-0015
|--------------------------------------------------------------------------
|
| The consolidated Definition-of-Done gate for the Media Integration task. Each
| section is a clause of the media invariants, pinned across ALL FOUR media
| modules (designs, BAST, report_media, financing_documents) through the ONE
| shared mechanism (MediaService + HasMedia + MediaController signed route).
|
| Per-module coverage also lives in MediaFoundationTest, BastMediaTest,
| MandorReportMediaTest and FinancingDocumentMediaTest; this file is the
| single-glance guarantee.
|
| Invariants:
|   (a) No public URL — files are reachable ONLY through the signed, policy-checked
|       route; the media disk is private and has no public URL.
|   (b) Two layers, every module — an expired signature is refused, and a valid
|       signature is NOT enough: the module policy still decides (unauthorized 403).
|   (c) Sensitive documents never leak — a financing document is unreachable by a
|       naked/guessed URL, denied to Manager/Finance/other bank/other consumer even
|       with a valid signature, and its file pointer is redacted in the audit trail.
|   (d) Server-side validation — each module refuses a disallowed type and an
|       oversize file; the MIME is read from the file CONTENT, never a client claim.
|   (e) One mechanism — every media model implements HasMedia and flows through
|       MediaService; the `file` column stays a plain string (ADR-0015); there is
|       NO central media table.
|   (f) Correct guarding ability — each module's descriptor names the right view
|       ability, and the serve route enforces exactly that module's policy.
|
*/

use App\Contracts\HasMedia;
use App\Enums\Bidang;
use App\Enums\ReportMediaType;
use App\Exceptions\MediaException;
use App\Models\AuditLog;
use App\Models\Bast;
use App\Models\DailyReport;
use App\Models\Design;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\ReportMedia;
use App\Models\Role;
use App\Models\User;
use App\Services\MediaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    $this->media = app(MediaService::class);
});

function dodMediaUser(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

function dodImage(): UploadedFile
{
    return UploadedFile::fake()->image('f.jpg');
}

function dodPdf(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

/**
 * One document per media module, each with a stored file plus the users who MAY
 * and MUST NOT view it.
 *
 * @return array<int, array{label: string, model: HasMedia&Model, allowed: list<User>, denied: list<User>}>
 */
function dodMediaCases(MediaService $media): array
{
    // designs — guarded by the project view policy.
    $design = Design::factory()->create(['file' => $media->store(new Design, dodImage())]);

    // BAST — guarded by the project view policy; attachment is a PDF.
    $bast = Bast::factory()->create(['file' => $media->store(new Bast, dodPdf())]);

    // report_media — guarded by the parent report's view policy.
    $mandor = dodMediaUser('mandor', Bidang::Cufid);
    $repKonsumen = dodMediaUser('konsumen');
    $repProject = Project::factory()->inBidang(Bidang::Cufid)->create(['konsumen_id' => $repKonsumen->id]);
    $report = DailyReport::factory()->create(['project_id' => $repProject->id, 'mandor_id' => $mandor->id]);
    $reportMedia = $report->media()->create([
        'type' => ReportMediaType::Photo,
        'file' => $media->store(new ReportMedia, dodImage()),
    ]);

    // financing_document — the strictest: owning consumer + owning bank + O/D only.
    $fdKonsumen = dodMediaUser('konsumen');
    $fdBank = dodMediaUser('mitra_pembiayaan');
    $financing = Financing::factory()
        ->forProject(Project::factory()->ownedBy($fdKonsumen)->create())
        ->ownedBy($fdKonsumen)->forBank($fdBank)->create();
    $financingDoc = FinancingDocument::factory()->forFinancing($financing)
        ->create(['file' => $media->store(new FinancingDocument, dodImage())]);

    return [
        ['label' => 'design', 'model' => $design, 'allowed' => [dodMediaUser('owner')], 'denied' => [dodMediaUser('konsumen')]],
        ['label' => 'bast', 'model' => $bast, 'allowed' => [dodMediaUser('direktur')], 'denied' => [dodMediaUser('konsumen')]],
        ['label' => 'report_media', 'model' => $reportMedia, 'allowed' => [$repKonsumen], 'denied' => [dodMediaUser('mandor', Bidang::Cc)]],
        ['label' => 'financing_document', 'model' => $financingDoc, 'allowed' => [$fdKonsumen, $fdBank], 'denied' => [dodMediaUser('manager', Bidang::Cufid), dodMediaUser('finance')]],
    ];
}

// ===========================================================================
// (a) NO PUBLIC URL — private disk; reachable only via the signed route
// ===========================================================================

it('(a) keeps the media disk private and serves only through the signed route', function () {
    // The disk config declares no public visibility/url.
    $disk = config('filesystems.disks.'.config('media.disk'));
    expect($disk['driver'])->toBe('local')
        ->and($disk)->not->toHaveKey('url')
        ->and($disk['visibility'] ?? null)->not->toBe('public');

    // A file exists only behind the signed route — an unsigned hit is refused.
    foreach (dodMediaCases($this->media) as $case) {
        $this->actingAs($case['allowed'][0])
            ->get(route('media.show', ['type' => $case['label'], 'id' => $case['model']->getKey()]))
            ->assertForbidden(); // no signature → 403 even for an allowed user
    }
});

// ===========================================================================
// (b) TWO LAYERS, EVERY MODULE — freshness AND authorization both required
// ===========================================================================

it('(b) enforces signature freshness AND policy on every module', function () {
    foreach (dodMediaCases($this->media) as $case) {
        $url = $this->media->temporaryUrl($case['model']);

        // Authorized + fresh → 200.
        $this->actingAs($case['allowed'][0])->get($url)->assertOk();

        // A VALID signature is not enough — the policy still refuses the outsider.
        foreach ($case['denied'] as $outsider) {
            $this->actingAs($outsider)->get($url)->assertForbidden("{$case['label']}: outsider must be 403 despite a valid signature");
        }

        // Authorized but EXPIRED signature → 403.
        $this->actingAs($case['allowed'][0]);
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden("{$case['label']}: expired signature must be 403");
        $this->travelBack();
    }
});

// ===========================================================================
// (c) SENSITIVE DOCUMENTS NEVER LEAK — financing_document is the tightest
// ===========================================================================

it('(c) never leaks a financing document by URL, role, or audit trail', function () {
    $fdKonsumen = dodMediaUser('konsumen');
    $fdBank = dodMediaUser('mitra_pembiayaan');
    $financing = Financing::factory()
        ->forProject(Project::factory()->ownedBy($fdKonsumen)->create())
        ->ownedBy($fdKonsumen)->forBank($fdBank)->create();
    $document = FinancingDocument::factory()->forFinancing($financing)
        ->create(['file' => $this->media->store(new FinancingDocument, dodImage())]);

    $url = $this->media->temporaryUrl($document);

    // Guest (no auth) → 401, even with a valid signature. Asserted first, before
    // any actingAs leaves a user authenticated.
    $this->getJson($url)->assertUnauthorized();

    // Naked/guessed URL (no signature) → refused before any policy check.
    $this->actingAs(dodMediaUser('owner'))
        ->get(route('media.show', ['type' => 'financing_document', 'id' => $document->id]))
        ->assertForbidden();

    // Every wrong role is 403 even with the valid signed URL.
    $manager = dodMediaUser('manager', Bidang::Cufid);
    foreach ([$manager, dodMediaUser('finance'), dodMediaUser('mitra_pembiayaan'), dodMediaUser('konsumen'), dodMediaUser('hr')] as $outsider) {
        $this->actingAs($outsider)->get($url)->assertForbidden("{$outsider->role->name} must not read the document");
    }

    // The sensitive file pointer is redacted in the audit trail.
    $audit = AuditLog::where('entity', FinancingDocument::class)
        ->where('entity_id', $document->id)->where('action', 'created')->sole();
    expect($audit->after['file'] ?? null)->toBe('[redacted]')
        ->and(json_encode($audit->after))->not->toContain((string) $document->file);
});

// ===========================================================================
// (d) SERVER-SIDE VALIDATION — each module refuses a disallowed type & oversize
// ===========================================================================

it('(d) validates type and size server-side per module', function () {
    $video = fn (): UploadedFile => UploadedFile::fake()->createWithContent('v.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
    $oversizeImage = fn (): UploadedFile => UploadedFile::fake()->image('big.jpg')->size(6000);

    // A type NOT allowed for each module is rejected.
    expect(fn () => $this->media->store(new Design, $video()))->toThrow(MediaException::class)            // designs: image+pdf, no video
        ->and(fn () => $this->media->store(new Bast, dodImage()))->toThrow(MediaException::class)          // bast: pdf only, no image
        ->and(fn () => $this->media->store(new ReportMedia, dodPdf()))->toThrow(MediaException::class)     // report: image+video, no pdf
        ->and(fn () => $this->media->store(new FinancingDocument, $video()))->toThrow(MediaException::class); // financing: image+pdf, no video

    // Oversize is rejected everywhere the type would otherwise be allowed.
    expect(fn () => $this->media->store(new Design, $oversizeImage()))->toThrow(MediaException::class)
        ->and(fn () => $this->media->store(new FinancingDocument, $oversizeImage()))->toThrow(MediaException::class);

    // Nothing was written on any rejection.
    expect(Storage::disk('media')->allFiles())->toBeEmpty();
});

// ===========================================================================
// (e) ONE MECHANISM — HasMedia + MediaService; file stays string; no media table
// ===========================================================================

it('(e) routes every module through one mechanism with no central media table', function () {
    // Every registered media model implements HasMedia and resolves a descriptor.
    foreach (config('media.models') as $alias => $class) {
        expect(is_subclass_of($class, HasMedia::class))->toBeTrue("{$alias} must implement HasMedia")
            ->and((new $class)->mediaDescriptor()->column)->toBe('file');
    }

    // All four modules are registered.
    expect(array_keys(config('media.models')))
        ->toEqualCanonicalizing(['design', 'bast', 'report_media', 'financing_document']);

    // The `file` column is a plain string on each table (ADR-0015) — not a morph,
    // and there is NO central media table.
    foreach (['designs', 'bast', 'report_media', 'financing_documents'] as $table) {
        expect(Schema::getColumnType($table, 'file'))->toBeIn(['string', 'varchar', 'text']);
    }
    expect(Schema::hasTable('media'))->toBeFalse();
});

// ===========================================================================
// (f) CORRECT GUARDING ABILITY — right ability, right policy, per module
// ===========================================================================

it('(f) guards each module with its own view ability and policy', function () {
    // Every descriptor guards with the `view` ability (resolved to the module's
    // own policy) — designs/bast → project view, report_media → report view,
    // financing_document → the strict document view.
    expect((new Design)->mediaDescriptor()->viewAbility)->toBe('view')
        ->and((new Bast)->mediaDescriptor()->viewAbility)->toBe('view')
        ->and((new ReportMedia)->mediaDescriptor()->viewAbility)->toBe('view')
        ->and((new FinancingDocument)->mediaDescriptor()->viewAbility)->toBe('view');

    // And the enforced policy really differs per module: the SAME actor is judged
    // by each module's own rule. A Manager (Cufid) may see a project's design
    // (project-scoped view) but NEVER a financing document (sensitive).
    $manager = dodMediaUser('manager', Bidang::Cufid);

    $design = Design::factory()->create([
        'project_id' => Project::factory()->inBidang(Bidang::Cufid)->create()->id,
        'file' => $this->media->store(new Design, dodImage()),
    ]);
    $konsumen = dodMediaUser('konsumen');
    $financing = Financing::factory()
        ->forProject(Project::factory()->ownedBy($konsumen)->create())
        ->ownedBy($konsumen)->forBank(dodMediaUser('mitra_pembiayaan'))->create();
    $document = FinancingDocument::factory()->forFinancing($financing)
        ->create(['file' => $this->media->store(new FinancingDocument, dodImage())]);

    $this->actingAs($manager)->get($this->media->temporaryUrl($design))->assertOk();          // project view: allowed
    $this->actingAs($manager)->get($this->media->temporaryUrl($document))->assertForbidden();  // sensitive doc: denied
});
