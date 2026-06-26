<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a deal→customer conversion is attempted in an invalid state
 * (B5). The UI guards these cases before offering the action; the service
 * re-checks so the invariant holds even if called directly.
 */
class DealConversionException extends RuntimeException
{
    public static function accountExists(string $email): self
    {
        return new self("Akun dengan email [{$email}] sudah ada.");
    }

    public static function notADeal(): self
    {
        return new self('Akun konsumen hanya dapat dibuat saat konsultasi berstatus deal.');
    }

    public static function alreadyHasCustomer(): self
    {
        return new self('Konsultasi ini sudah memiliki akun konsumen.');
    }

    public static function guestSessionGone(string $token): self
    {
        return new self("Sesi tamu [{$token}] sudah berakhir; tidak ada yang bisa dipromosikan.");
    }
}
