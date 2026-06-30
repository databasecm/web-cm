<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a deal→project conversion is attempted in an invalid state
 * (Fase 2B bridge). The UI guards these cases before offering the action; the
 * service re-checks so the invariant holds even if called directly.
 */
class ProjectConversionException extends RuntimeException
{
    public static function notADeal(): self
    {
        return new self('Proyek hanya dapat dibuat dari konsultasi berstatus deal.');
    }

    public static function noCustomerAccount(): self
    {
        return new self('Konsultasi belum memiliki akun konsumen; buat akun konsumen lebih dahulu.');
    }

    public static function alreadyHasProject(): self
    {
        return new self('Deal ini sudah memiliki proyek.');
    }
}
