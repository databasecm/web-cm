<?php

namespace App\Enums;

/**
 * Lifecycle of a material purchase order (Fase 6-5):
 * - draft     : being prepared (Manager/Finance), editable.
 * - ordered   : sent to the supplier; still editable, not yet realised.
 * - received  : goods in hand — posts the material expense to the cash book
 *               (the cash-out moment) and becomes FINAL/immutable.
 * - cancelled : abandoned before receipt (from draft or ordered) — FINAL, no
 *               expense.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Dipesan',
            self::Received => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Received/cancelled are terminal — the PO can no longer change. */
    public function isFinal(): bool
    {
        return $this === self::Received || $this === self::Cancelled;
    }
}
