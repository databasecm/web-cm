<?php

namespace App\Services;

use App\Models\Ahsap;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Recomputes an AHSAP's base_price from its components (ADR-0004):
 * base_price = Σ(coefficient × unit_price).
 *
 * Money maths use BigDecimal, never float (ADR-0005): the sum is exact and only
 * the final result is rounded to 2 decimals (HALF_UP). The write is quiet
 * (saveQuietly): routine recalculation from component edits must not spam the
 * audit trail — deliberate base_price changes (the resync action, Fase 2A-3) are
 * audited explicitly there.
 */
class AhsapCalculator
{
    public function recalculate(Ahsap $ahsap): Ahsap
    {
        $total = BigDecimal::zero();

        foreach ($ahsap->components()->get() as $component) {
            $total = $total->plus(
                BigDecimal::of((string) $component->coefficient)
                    ->multipliedBy((string) $component->unit_price)
            );
        }

        $ahsap->base_price = (string) $total->toScale(2, RoundingMode::HALF_UP);
        $ahsap->saveQuietly();

        return $ahsap;
    }
}
