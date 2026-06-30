<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid installment unlock attempt (Fase 2B-6).
 */
class InstallmentException extends RuntimeException
{
    /**
     * The pelunasan (BAST) term can never open before the BAST is signed
     * (CLAUDE.md §7). BAST is digital in Fase 3 — until then it stays locked.
     */
    public static function bastRequired(): self
    {
        return new self('Termin pelunasan tidak dapat dibuka sebelum BAST ditandatangani.');
    }

    public static function progressNotReached(): self
    {
        return new self('Termin progres baru terbuka saat progres proyek mencapai 50%.');
    }
}
