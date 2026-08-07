<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;

/**
 * E11 — a material PO was received (goods in hand → expense posted, Fase 7-4).
 *
 * Internal only: the consumer is not a recipient. The body names the PO and its
 * project but NEVER the price — the figure is behind the policy-guarded link.
 */
class PurchaseOrderReceivedNotification extends BaseNotification
{
    public function __construct(public PurchaseOrder $po) {}

    public function event(): string
    {
        return 'po.received';
    }

    public function title(): string
    {
        return 'PO material diterima';
    }

    public function body(): string
    {
        return "PO {$this->po->po_number} untuk proyek #{$this->po->project_id} diterima.";
    }

    public function entityType(): ?string
    {
        return 'purchase_order';
    }

    public function entityId(): int|string|null
    {
        return $this->po->id;
    }

    public function actionUrl(): ?string
    {
        return url("/purchase-orders/{$this->po->id}");
    }
}
