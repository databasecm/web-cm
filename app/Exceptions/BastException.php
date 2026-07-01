<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a BAST invariant is violated (Fase 3-1).
 */
class BastException extends RuntimeException
{
    /**
     * A BAST may only become `signed` once BOTH parties have signed
     * (signed_customer && signed_company). Guards every write path so the state
     * can never be forced without the two signatures.
     */
    public static function signaturesRequired(): self
    {
        return new self('BAST hanya dapat berstatus ditandatangani setelah kedua pihak menandatangani.');
    }
}
