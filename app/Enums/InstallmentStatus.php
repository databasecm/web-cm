<?php

namespace App\Enums;

/**
 * Lifecycle of an installment (ERD §A.4):
 * - locked   : its due condition is not yet met (cannot be billed/paid).
 * - unlocked : payable now.
 * - paid     : settled.
 */
enum InstallmentStatus: string
{
    case Locked = 'locked';
    case Unlocked = 'unlocked';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Locked => 'Terkunci',
            self::Unlocked => 'Terbuka',
            self::Paid => 'Lunas',
        };
    }
}
