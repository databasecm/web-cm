<?php

namespace App\Enums;

/**
 * Direction of a cash-book entry (ERD §A.6): money in or money out.
 */
enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Pemasukan',
            self::Expense => 'Pengeluaran',
        };
    }
}
