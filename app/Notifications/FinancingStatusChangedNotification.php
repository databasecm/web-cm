<?php

namespace App\Notifications;

use App\Models\Financing;

/**
 * E6 — a financing application changed lifecycle status (Fase 7-5).
 *
 * Sensitive domain (§6.5): the body is a neutral knock — the status label and
 * the project reference, never the amount. Recipients are only the applicant
 * and the owning bank (RecipientResolver).
 */
class FinancingStatusChangedNotification extends BaseNotification
{
    public function __construct(public Financing $financing) {}

    public function event(): string
    {
        return 'financing.status_changed';
    }

    public function title(): string
    {
        return 'Status pembiayaan diperbarui';
    }

    public function body(): string
    {
        return "Status pengajuan pembiayaan proyek #{$this->financing->project_id}: {$this->financing->status->label()}.";
    }

    public function entityType(): ?string
    {
        return 'financing';
    }

    public function entityId(): int|string|null
    {
        return $this->financing->id;
    }

    public function actionUrl(): ?string
    {
        return url("/financings/{$this->financing->id}");
    }
}
