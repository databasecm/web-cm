<?php

namespace App\Enums;

/**
 * Author side of a consultation message. The consumer side (`konsumen`) and the
 * staff side (`manager`) are the only two participants in a thread; routing and
 * the per-bidang inbox decide which staff account actually responds.
 */
enum SenderType: string
{
    case Konsumen = 'konsumen';
    case Manager = 'manager';

    /**
     * Human-readable label (Indonesian UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Konsumen => 'Konsumen',
            self::Manager => 'Manager',
        };
    }
}
