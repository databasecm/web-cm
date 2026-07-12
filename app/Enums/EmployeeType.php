<?php

namespace App\Enums;

/**
 * Employment type of a worker (ERD §A.5):
 * - harian  : daily-wage worker, paid weekly per attended day (CLAUDE.md §7).
 * - bulanan : monthly-salaried worker.
 */
enum EmployeeType: string
{
    case Harian = 'harian';
    case Bulanan = 'bulanan';

    public function label(): string
    {
        return match ($this) {
            self::Harian => 'Harian',
            self::Bulanan => 'Bulanan',
        };
    }
}
