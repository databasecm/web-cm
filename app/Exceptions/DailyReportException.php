<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid daily-report operation (Fase 5-3).
 */
class DailyReportException extends RuntimeException
{
    /**
     * One report per project per day.
     */
    public static function alreadyExists(): self
    {
        return new self('Laporan harian untuk proyek ini pada tanggal tersebut sudah ada.');
    }
}
