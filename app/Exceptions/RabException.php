<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid RAB state transition (Fase 2B-5).
 */
class RabException extends RuntimeException
{
    public static function notDraft(): self
    {
        return new self('Hanya RAB berstatus draft yang dapat diajukan.');
    }

    public static function notSubmitted(): self
    {
        return new self('Hanya RAB yang diajukan yang dapat disetujui.');
    }
}
