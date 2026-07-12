<?php

use App\Enums\FinancingDocumentStatus;
use App\Enums\FinancingStatus;
use App\Exceptions\FinancingException;
use App\Models\AuditLog;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancingDocumentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->docs = app(FinancingDocumentService::class);
});

function fdocRoled(string $name): User
{
    return User::factory()->create(['role_id' => Role::where('name', $name)->value('id')]);
}

/** A submitted financing owned by $konsumen and banked by $bank. */
function fdocFinancing(User $konsumen, User $bank): Financing
{
    $project = Project::factory()->ownedBy($konsumen)->create();

    return Financing::factory()->forProject($project)->forBank($bank)->create();
}

// ---------------------------------------------------------------------------
// Upload — owning consumer
// ---------------------------------------------------------------------------

it('lets the owning consumer upload a document as pending', function () {
    $konsumen = fdocRoled('konsumen');
    $bank = fdocRoled('mitra_pembiayaan');
    $financing = fdocFinancing($konsumen, $bank);

    expect($konsumen->can('uploadFinancingDocument', $financing))->toBeTrue()
        ->and(fdocRoled('konsumen')->can('uploadFinancingDocument', $financing))->toBeFalse();

    $doc = $this->docs->upload($financing, 'KTP', 'financing/ktp.pdf', $konsumen);

    expect($doc->status)->toBe(FinancingDocumentStatus::Pending)
        ->and((int) $doc->uploaded_by)->toBe($konsumen->id)
        ->and($doc->name)->toBe('KTP');
});

// ---------------------------------------------------------------------------
// Review — owning bank only
// ---------------------------------------------------------------------------

it('lets the owning bank accept or reject, and nobody else', function () {
    $konsumen = fdocRoled('konsumen');
    $bank = fdocRoled('mitra_pembiayaan');
    $otherBank = fdocRoled('mitra_pembiayaan');
    $financing = fdocFinancing($konsumen, $bank);
    $doc = FinancingDocument::factory()->forFinancing($financing)->create();

    // Authorization (enforced by the caller in 4-4).
    expect($bank->can('review', $doc))->toBeTrue()
        ->and($otherBank->can('review', $doc))->toBeFalse()
        ->and($konsumen->can('review', $doc))->toBeFalse()   // consumer cannot review its own
        ->and(fdocRoled('manager')->can('review', $doc))->toBeFalse();

    $this->docs->reject($doc, $bank, 'buram, unggah ulang');

    expect($doc->refresh()->status)->toBe(FinancingDocumentStatus::Rejected)
        ->and($doc->note)->toBe('buram, unggah ulang')
        ->and((int) $doc->reviewed_by)->toBe($bank->id)
        ->and($doc->reviewed_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Request more documents → financing transitions to docs_required (via 4-2)
// ---------------------------------------------------------------------------

it('requesting more documents transitions the financing to docs_required with a log', function () {
    $financing = fdocFinancing(fdocRoled('konsumen'), $bank = fdocRoled('mitra_pembiayaan'));

    $this->docs->requestMore($financing, $bank, 'lengkapi slip gaji');

    expect($financing->fresh()->status)->toBe(FinancingStatus::DocsRequired)
        ->and($financing->statusLogs()->count())->toBe(1)
        ->and($financing->statusLogs()->latest('id')->first()->status)->toBe(FinancingStatus::DocsRequired);
});

// ---------------------------------------------------------------------------
// Immutability once the financing is final
// ---------------------------------------------------------------------------

it('locks documents once the financing is final', function () {
    $konsumen = fdocRoled('konsumen');
    $bank = fdocRoled('mitra_pembiayaan');

    // Rejected financing → no upload, no review.
    $rejected = Financing::factory()->forBank($bank)->status(FinancingStatus::Rejected)->create();
    expect(fn () => $this->docs->upload($rejected, 'KTP', null, $konsumen))->toThrow(FinancingException::class);

    $disbursed = Financing::factory()->forBank($bank)->status(FinancingStatus::Disbursed)->create();
    $doc = FinancingDocument::factory()->forFinancing($disbursed)->create();
    expect(fn () => $this->docs->accept($doc, $bank))->toThrow(FinancingException::class)
        ->and($doc->refresh()->status)->toBe(FinancingDocumentStatus::Pending); // unchanged
});

// ---------------------------------------------------------------------------
// Sensitive: the file pointer is redacted in the audit trail
// ---------------------------------------------------------------------------

it('never writes the document file pointer into the audit trail', function () {
    $financing = fdocFinancing(fdocRoled('konsumen'), fdocRoled('mitra_pembiayaan'));

    $this->docs->upload($financing, 'Slip Gaji', 'financing/secret-payslip.pdf');

    $audits = AuditLog::where('entity', FinancingDocument::class)->get();
    expect($audits)->not->toBeEmpty();
    foreach ($audits as $audit) {
        expect(json_encode($audit->after ?? []))->not->toContain('secret-payslip.pdf');
    }
});

// ---------------------------------------------------------------------------
// §6.5 — the document flow never grants project write access
// ---------------------------------------------------------------------------

it('keeps the bank read-only on projects throughout the document flow', function () {
    $bank = fdocRoled('mitra_pembiayaan');
    $financing = fdocFinancing(fdocRoled('konsumen'), $bank);
    $doc = FinancingDocument::factory()->forFinancing($financing)->create();

    expect($bank->can('review', $doc))->toBeTrue()
        ->and($bank->can('update', $financing->project))->toBeFalse()
        ->and($bank->can('delete', $financing->project))->toBeFalse();
});
