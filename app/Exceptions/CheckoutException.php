<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid checkout (Fase 2B-5).
 */
class CheckoutException extends RuntimeException
{
    public static function noContractValue(): self
    {
        return new self('Checkout memerlukan RAB yang sudah disetujui (nilai kontrak belum ada).');
    }

    public static function alreadyCheckedOut(): self
    {
        return new self('Proyek ini sudah checkout (jadwal termin sudah dibuat).');
    }
}
