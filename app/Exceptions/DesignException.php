<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid design state transition (Fase 2B-2).
 */
class DesignException extends RuntimeException
{
    public static function notDraft(): self
    {
        return new self('Hanya desain berstatus draft yang dapat diajukan.');
    }

    public static function notSubmitted(): self
    {
        return new self('Hanya desain yang diajukan yang dapat disetujui.');
    }
}
