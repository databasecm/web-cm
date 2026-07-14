<?php

namespace App\Enums;

/**
 * Payroll run type (ERD §A.5):
 * - weekly_daily : daily-wage workers, paid weekly on Saturday (CLAUDE.md §7).
 * - monthly      : monthly-salaried workers (a later task).
 */
enum PayrollType: string
{
    case WeeklyDaily = 'weekly_daily';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::WeeklyDaily => 'Harian (Mingguan)',
            self::Monthly => 'Bulanan',
        };
    }
}
