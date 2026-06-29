<?php

namespace App\Enums;

/**
 * Payment scheme chosen at checkout (konsep §5):
 * - termin3 : DP 30% (checkout) · 40% (progress ≥50%) · 30% (after BAST)
 * - fifty   : DP 50% (checkout) · 50% (after BAST)
 * - lunas   : 100% (after checkout)
 */
enum PaymentScheme: string
{
    case Termin3 = 'termin3';
    case Fifty = 'fifty';
    case Lunas = 'lunas';

    public function label(): string
    {
        return match ($this) {
            self::Termin3 => 'Termin 3x (30/40/30)',
            self::Fifty => '50 : 50',
            self::Lunas => 'Lunas',
        };
    }
}
