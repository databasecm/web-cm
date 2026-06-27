<?php

namespace App\Services;

use App\Models\Material;
use App\Models\User;
use App\Observers\MaterialObserver;

/**
 * Explicit entry point for changing a material's price (ADR-0004).
 *
 * The journal entry (and, from Fase 2A-3, the AHSAP staleness flag) is written by
 * {@see MaterialObserver} on save, so this service stays a thin,
 * intention-revealing wrapper: it attributes the change to an actor and is
 * idempotent — an unchanged price makes no journal row and flags nothing.
 */
class MaterialPriceService
{
    public function change(Material $material, float|string $newPrice, ?User $by = null): Material
    {
        // No-op when the price is unchanged: nothing is journalled or flagged.
        if ($this->normalize($newPrice) === $this->normalize($material->price)) {
            return $material;
        }

        $material->priceChangedBy = $by?->id;
        $material->price = $newPrice;
        $material->save();

        return $material;
    }

    /**
     * Compare prices as fixed 2-decimal strings so 70000 and 70000.00 match.
     */
    private function normalize(float|string $price): string
    {
        return number_format((float) $price, 2, '.', '');
    }
}
