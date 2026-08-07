<?php

namespace App\Notifications;

use App\Models\Rab;

/**
 * E12 — a RAB was approved by the consumer and frozen as the contract (Fase
 * 7-4). The body names the project, never the RAB total.
 */
class RabFinalizedNotification extends BaseNotification
{
    public function __construct(public Rab $rab) {}

    public function event(): string
    {
        return 'rab.finalized';
    }

    public function title(): string
    {
        return 'RAB difinalkan';
    }

    public function body(): string
    {
        return "RAB proyek #{$this->rab->project_id} telah difinalkan.";
    }

    public function entityType(): ?string
    {
        return 'rab';
    }

    public function entityId(): int|string|null
    {
        return $this->rab->id;
    }

    public function actionUrl(): ?string
    {
        return url("/projects/{$this->rab->project_id}");
    }
}
