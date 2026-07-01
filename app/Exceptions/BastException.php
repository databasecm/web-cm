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

    /**
     * A BAST is only issued once the project is active (has a contract and
     * installment schedule).
     */
    public static function projectNotActive(): self
    {
        return new self('BAST hanya dapat diterbitkan untuk proyek yang sudah berjalan.');
    }

    /**
     * One BAST per project (unique project_id, Fase 3-1). Re-issuing is refused
     * with a clean domain error instead of a database constraint violation.
     */
    public static function alreadyIssued(): self
    {
        return new self('BAST untuk proyek ini sudah diterbitkan.');
    }
}
