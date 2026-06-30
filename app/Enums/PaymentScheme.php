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

    /**
     * The installment schedule for this scheme (konsep §5): percentage, when it
     * becomes due, and a label. The percentages sum to 100; the last term takes
     * the rounding remainder so Σ amounts == contract_value exactly.
     *
     * @return array<int, array{percentage: string, due_condition: DueCondition, label: string}>
     */
    public function terms(): array
    {
        return match ($this) {
            self::Termin3 => [
                ['percentage' => '30', 'due_condition' => DueCondition::Checkout, 'label' => 'DP'],
                ['percentage' => '40', 'due_condition' => DueCondition::Progress50, 'label' => 'Progres'],
                ['percentage' => '30', 'due_condition' => DueCondition::Bast, 'label' => 'Pelunasan'],
            ],
            self::Fifty => [
                ['percentage' => '50', 'due_condition' => DueCondition::Checkout, 'label' => 'DP'],
                ['percentage' => '50', 'due_condition' => DueCondition::Bast, 'label' => 'Pelunasan'],
            ],
            self::Lunas => [
                ['percentage' => '100', 'due_condition' => DueCondition::Checkout, 'label' => 'Lunas'],
            ],
        };
    }
}
