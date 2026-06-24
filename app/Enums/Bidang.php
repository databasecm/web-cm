<?php

namespace App\Enums;

/**
 * Business units (bidang) of CV. Cimandiri. Scopes data access for
 * Manager (level 3) and Mandor (level 5) accounts.
 */
enum Bidang: string
{
    case Cufid = 'cufid';
    case Cc = 'cc';
    case Solit = 'solit';
    case BiruGis = 'birugis';

    /**
     * Human-readable label (Indonesian UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Cufid => 'CuFID (Furniture)',
            self::Cc => 'Custom Construction',
            self::Solit => 'SolIT',
            self::BiruGis => 'BIRU GIS',
        };
    }
}
