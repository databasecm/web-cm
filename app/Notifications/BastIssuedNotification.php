<?php

namespace App\Notifications;

use App\Models\Bast;

/**
 * E2 — a BAST (handover minutes) was issued and is ready to sign (Fase 7-3).
 */
class BastIssuedNotification extends BaseNotification
{
    public function __construct(public Bast $bast) {}

    public function event(): string
    {
        return 'bast.issued';
    }

    public function title(): string
    {
        return 'BAST siap ditandatangani';
    }

    public function body(): string
    {
        return "BAST proyek #{$this->bast->project_id} siap ditandatangani.";
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
