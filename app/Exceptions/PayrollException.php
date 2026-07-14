<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid payroll payment (Fase 6-2).
 */
class PayrollException extends RuntimeException
{
    /**
     * A payroll is paid exactly once; a second payment is refused so the cash
     * book never gets a duplicate salary expense.
     */
    public static function alreadyPaid(): self
    {
        return new self('Payroll ini sudah dibayar.');
    }
}
