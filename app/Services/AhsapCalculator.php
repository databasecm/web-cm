<?php

namespace App\Services;

use App\Models\Ahsap;

/**
 * Recomputes an AHSAP's base_price from its components (ADR-0004):
 * base_price = Σ(coefficient × unit_price).
 *
 * The write is quiet (saveQuietly): routine recalculation from component edits
 * must not spam the audit trail. Deliberate base_price changes — the resync
 * action (Fase 2A-3) — are audited explicitly there.
 */
class AhsapCalculator
{
    public function recalculate(Ahsap $ahsap): Ahsap
    {
        $total = 0.0;

        foreach ($ahsap->components()->get() as $component) {
            $total += round((float) $component->coefficient * (float) $component->unit_price, 2);
        }

        $ahsap->base_price = number_format($total, 2, '.', '');
        $ahsap->saveQuietly();

        return $ahsap;
    }
}
