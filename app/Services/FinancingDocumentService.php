<?php

namespace App\Services;

use App\Enums\FinancingDocumentStatus;
use App\Enums\FinancingStatus;
use App\Exceptions\FinancingException;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\User;

/**
 * Financing document flow (Fase 4-3). Pure service + guards; authorization is the
 * caller's job (uploadFinancingDocument gate / FinancingDocumentPolicy::review).
 *
 * Scope separation (§6.5): everything here touches only financing_documents and,
 * for "request more", the financing lifecycle via FinancingService — never the
 * projects table.
 *
 * Immutability: once the parent financing is FINAL (rejected/disbursed) its
 * documents can no longer be uploaded or reviewed.
 */
class FinancingDocumentService
{
    public function __construct(private FinancingService $financings) {}

    /**
     * The consumer uploads/records a requirement document → pending review.
     */
    public function upload(Financing $financing, string $name, ?string $file = null, ?User $by = null): FinancingDocument
    {
        $this->assertNotFinal($financing);

        return FinancingDocument::create([
            'financing_id' => $financing->id,
            'name' => $name,
            'file' => $file,
            'status' => FinancingDocumentStatus::Pending,
            'uploaded_by' => $by?->id,
        ]);
    }

    /**
     * The bank accepts a document.
     */
    public function accept(FinancingDocument $document, ?User $by = null): FinancingDocument
    {
        return $this->review($document, FinancingDocumentStatus::Accepted, $by, null);
    }

    /**
     * The bank rejects a document, with a reason.
     */
    public function reject(FinancingDocument $document, ?User $by = null, ?string $note = null): FinancingDocument
    {
        return $this->review($document, FinancingDocumentStatus::Rejected, $by, $note);
    }

    /**
     * The bank asks the consumer for more documents. This is expressed as a
     * financing transition to docs_required — reusing FinancingService so the
     * legal-transition guard and status-log trail hold (no parallel path).
     */
    public function requestMore(Financing $financing, ?User $by = null, ?string $note = null): Financing
    {
        return $this->financings->transition($financing, FinancingStatus::DocsRequired, $by, $note);
    }

    private function review(FinancingDocument $document, FinancingDocumentStatus $status, ?User $by, ?string $note): FinancingDocument
    {
        $this->assertNotFinal($document->financing);

        $document->update([
            'status' => $status,
            'note' => $note,
            'reviewed_by' => $by?->id,
            'reviewed_at' => now(),
        ]);

        return $document;
    }

    private function assertNotFinal(?Financing $financing): void
    {
        if ($financing !== null && $financing->status->isFinal()) {
            throw FinancingException::documentsLocked();
        }
    }
}
