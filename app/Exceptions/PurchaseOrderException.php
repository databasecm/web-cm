<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid purchase-order transition (Fase 6-5).
 */
class PurchaseOrderException extends RuntimeException
{
    /** Only a draft can be ordered. */
    public static function notOrderable(): self
    {
        return new self('Hanya PO draft yang bisa dipesan.');
    }

    /** A PO cannot be ordered with no line items. */
    public static function empty(): self
    {
        return new self('PO tidak memiliki item — tambahkan item sebelum dipesan.');
    }

    /** Only an ordered PO can be received. */
    public static function notReceivable(): self
    {
        return new self('Hanya PO yang sudah dipesan yang bisa diterima.');
    }

    /**
     * A PO is received exactly once; a second receipt is refused so the cash
     * book never gets a duplicate material expense.
     */
    public static function alreadyReceived(): self
    {
        return new self('PO ini sudah diterima.');
    }

    /** Received/cancelled POs are final and cannot be cancelled. */
    public static function notCancellable(): self
    {
        return new self('PO yang sudah diterima atau dibatalkan tidak bisa dibatalkan.');
    }
}
