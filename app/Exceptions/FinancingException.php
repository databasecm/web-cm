<?php

namespace App\Exceptions;

use App\Enums\FinancingStatus;
use RuntimeException;

/**
 * Raised on an invalid financing operation (Fase 4-1).
 */
class FinancingException extends RuntimeException
{
    public static function invalidTransition(FinancingStatus $from, FinancingStatus $to): self
    {
        return new self("Transisi pembiayaan tidak sah: {$from->value} → {$to->value}.");
    }

    /**
     * One active (non-final) financing per project. A new application is refused
     * while an existing one is still in progress.
     */
    public static function alreadyActive(): self
    {
        return new self('Proyek ini masih memiliki pengajuan pembiayaan yang aktif.');
    }
}
