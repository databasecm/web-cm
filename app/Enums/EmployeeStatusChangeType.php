<?php

namespace App\Enums;

/**
 * Kind of change recorded in a worker's history (ERD §A.5):
 * - promotion : a change of position/title.
 * - salary    : a change of daily wage.
 */
enum EmployeeStatusChangeType: string
{
    case Promotion = 'promotion';
    case Salary = 'salary';

    public function label(): string
    {
        return match ($this) {
            self::Promotion => 'Kenaikan Jabatan',
            self::Salary => 'Perubahan Gaji',
        };
    }
}
