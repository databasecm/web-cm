<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised on an invalid attendance operation (Fase 5-2). Attendance is the payroll
 * source, so these guards protect against double wages and bad data.
 */
class AttendanceException extends RuntimeException
{
    /**
     * A worker attends one project per day: a second attendance for the same
     * (employee, date) — even on a different project — is refused.
     */
    public static function alreadyRecorded(): self
    {
        return new self('Karyawan ini sudah diabsen pada tanggal tersebut.');
    }

    /** Only an active worker can be attended. */
    public static function employeeInactive(): self
    {
        return new self('Karyawan nonaktif tidak dapat diabsen.');
    }

    /** The worker and the project must be in the same bidang. */
    public static function bidangMismatch(): self
    {
        return new self('Karyawan dan proyek harus berada pada bidang yang sama.');
    }

    /**
     * The period's payroll has been paid, so its attendance is frozen and can no
     * longer be added to or corrected (ADR-0016).
     */
    public static function periodLocked(): self
    {
        return new self('Absensi periode ini terkunci karena payroll sudah dibayar.');
    }
}
