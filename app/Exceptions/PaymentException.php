<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid installment payment attempt (Fase 3-4).
 */
class PaymentException extends RuntimeException
{
    /**
     * Only an unlocked term is payable. A locked term — progress50 below 50% or
     * a pelunasan without a signed BAST — is rejected (CLAUDE.md §7, 2B-6).
     */
    public static function notPayable(): self
    {
        return new self('Termin ini belum dapat dibayar (masih terkunci).');
    }

    /**
     * A term is paid exactly once; a second payment is refused so the cash book
     * never gets a duplicate income row.
     */
    public static function alreadyPaid(): self
    {
        return new self('Termin ini sudah dibayar.');
    }

    /**
     * A gateway callback whose signature/reference does not verify (Fase 3-6):
     * it is rejected without touching any state.
     */
    public static function invalidCallback(): self
    {
        return new self('Callback pembayaran tidak sah.');
    }
}
