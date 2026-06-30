<?php

namespace App\Enums;

/**
 * Lifecycle of a design version (ERD §A.2):
 * - draft     : Manager is still preparing it.
 * - submitted : sent to the consumer for approval.
 * - approved  : the consumer approved this version.
 */
enum DesignStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
        };
    }
}
