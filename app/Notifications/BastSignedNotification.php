<?php

namespace App\Notifications;

use App\Models\Bast;

/**
 * E3 — a BAST was signed by both parties (Fase 7-3). The pelunasan term is now
 * open, which is why Finance joins the recipients (see RecipientResolver).
 */
class BastSignedNotification extends BaseNotification
{
    public function __construct(public Bast $bast) {}

    public function event(): string
    {
        return 'bast.signed';
    }

    public function title(): string
    {
        return 'BAST telah ditandatangani';
    }

    public function body(): string
    {
        return "BAST proyek #{$this->bast->project_id} telah ditandatangani.";
    }

    public function entityType(): ?string
    {
        return 'bast';
    }

    public function entityId(): int|string|null
    {
        return $this->bast->id;
    }

    public function actionUrl(): ?string
    {
        return url("/projects/{$this->bast->project_id}");
    }
}
