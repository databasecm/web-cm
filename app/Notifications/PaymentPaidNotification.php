<?php

namespace App\Notifications;

use App\Models\Installment;

/**
 * E1 — a consumer installment (term or pelunasan) was paid (Fase 7-3).
 *
 * Neutral body by design: it names the project, never the amount. The figure
 * lives behind `action_url`, which the destination re-checks against policy.
 */
class PaymentPaidNotification extends BaseNotification
{
    public function __construct(public Installment $installment) {}

    public function event(): string
    {
        return 'payment.paid';
    }

    public function title(): string
    {
        return 'Pembayaran diterima';
    }

    public function body(): string
    {
        return "Pembayaran termin proyek #{$this->installment->project_id} diterima.";
    }

    public function entityType(): ?string
    {
        return 'installment';
    }

    public function entityId(): int|string|null
    {
        return $this->installment->id;
    }

    public function actionUrl(): ?string
    {
        return url("/projects/{$this->installment->project_id}");
    }
}
