<?php

namespace App\Notifications;

use App\Models\FinancingDocument;

/**
 * E8 — the bank reviewed (accepted/rejected) a financing document (Fase 7-5).
 *
 * ONLY the owning consumer is notified. The body names the document and its new
 * status — never the reviewer's note/reason and never the document's content or
 * file pointer (that stays behind FinancingDocumentPolicy, 4-3/media-4).
 */
class FinancingDocumentReviewedNotification extends BaseNotification
{
    public function __construct(public FinancingDocument $document) {}

    public function event(): string
    {
        return 'financing_document.reviewed';
    }

    public function title(): string
    {
        return 'Dokumen pembiayaan ditinjau';
    }

    public function body(): string
    {
        return "Dokumen '{$this->document->name}' Anda telah {$this->document->status->label()}.";
    }

    public function entityType(): ?string
    {
        return 'financing_document';
    }

    public function entityId(): int|string|null
    {
        return $this->document->id;
    }

    public function actionUrl(): ?string
    {
        return url("/financings/{$this->document->financing_id}");
    }
}
