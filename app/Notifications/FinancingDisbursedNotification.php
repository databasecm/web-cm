<?php

namespace App\Notifications;

use App\Models\Financing;

/**
 * E7 — a financing was disbursed (Fase 7-5). Cash out, so the overseers join
 * the applicant and the owning bank. No amount in the body (§6.5).
 */
class FinancingDisbursedNotification extends BaseNotification
{
    public function __construct(public Financing $financing) {}

    public function event(): string
    {
        return 'financing.disbursed';
    }

    public function title(): string
    {
        return 'Pembiayaan dicairkan';
    }

    public function body(): string
    {
        return "Pembiayaan proyek #{$this->financing->project_id} telah dicairkan.";
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
