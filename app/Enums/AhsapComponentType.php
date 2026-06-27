<?php

namespace App\Enums;

/**
 * Kind of an AHSAP component: material, labour (upah) or tools (alat). Only the
 * material kind links to the Material database for its unit price (ADR-0004);
 * upah and alat carry a manually entered unit price.
 */
enum AhsapComponentType: string
{
    case Material = 'material';
    case Upah = 'upah';
    case Alat = 'alat';

    /**
     * Human-readable label (Indonesian UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Material => 'Material',
            self::Upah => 'Upah',
            self::Alat => 'Alat',
        };
    }
}
