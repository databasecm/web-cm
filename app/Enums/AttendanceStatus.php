<?php

namespace App\Enums;

/**
 * Daily attendance status of a worker (ERD §A.5). Day-level only — no clock
 * in/out — since daily payroll is "attended days × wage" (CLAUDE.md §7).
 */
enum AttendanceStatus: string
{
    case Hadir = 'hadir';
    case Izin = 'izin';
    case Alpa = 'alpa';

    public function label(): string
    {
        return match ($this) {
            self::Hadir => 'Hadir',
            self::Izin => 'Izin',
            self::Alpa => 'Alpa',
        };
    }
}
