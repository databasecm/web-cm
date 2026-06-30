<?php

namespace App\Enums;

/**
 * When an installment becomes payable (konsep §5, ERD §A.4):
 * - checkout   : due immediately after checkout (unlocked).
 * - progress50 : unlocks when project progress reaches 50%.
 * - bast       : unlocks after the BAST is signed (the pelunasan).
 */
enum DueCondition: string
{
    case Checkout = 'checkout';
    case Progress50 = 'progress50';
    case Bast = 'bast';

    public function label(): string
    {
        return match ($this) {
            self::Checkout => 'Setelah checkout',
            self::Progress50 => 'Progres ≥ 50%',
            self::Bast => 'Setelah BAST',
        };
    }
}
