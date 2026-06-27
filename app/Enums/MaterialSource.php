<?php

namespace App\Enums;

/**
 * Where a material record came from: a registered supplier's price list, or
 * internal / field (mandor) input.
 */
enum MaterialSource: string
{
    case Supplier = 'supplier';
    case Internal = 'internal';

    /**
     * Human-readable label (Indonesian UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Internal => 'Internal',
        };
    }
}
