<?php

namespace App\Enums;

/**
 * Lifecycle of a RAB version (ERD §A.2):
 * - draft     : Manager is building / revising it.
 * - submitted : sent to the consumer for approval.
 * - approved  : the consumer approved this version (frozen; revisions are new
 *               versions, ADR-0007).
 */
enum RabStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Superseded => 'Digantikan',
        };
    }
}
