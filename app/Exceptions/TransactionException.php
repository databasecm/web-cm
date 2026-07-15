<?php

namespace App\Exceptions;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use RuntimeException;

/**
 * Raised on an invalid manual cash-book entry (Fase 6-3).
 */
class TransactionException extends RuntimeException
{
    /**
     * A category that is posted automatically (installment/payroll/PO) may never
     * be hand-entered, or the cash book double-counts the same money.
     */
    public static function categoryNotManual(TransactionCategory $category, TransactionType $type): self
    {
        return new self(
            "Kategori {$category->label()} tidak boleh diinput manual untuk {$type->value} (sumbernya otomatis)."
        );
    }
}
