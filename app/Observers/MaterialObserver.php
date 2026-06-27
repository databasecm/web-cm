<?php

namespace App\Observers;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Services\MaterialPriceService;
use Illuminate\Support\Facades\Auth;

/**
 * Journals every material price point to material_price_history (ADR-0004).
 *
 * This observer is the SINGLE writer of price history: an initial row on create,
 * one row per change on update. {@see MaterialPriceService} is the
 * explicit call site, but because recording lives here, a direct
 * `$material->update(['price' => …])` is caught too — and never double-records,
 * since one save produces at most one journal row. (AHSAP staleness flagging is
 * layered on the same single trigger in Fase 2A-3.)
 */
class MaterialObserver
{
    public function created(Material $material): void
    {
        $this->record($material, (string) $material->price);
    }

    public function updated(Material $material): void
    {
        if (! array_key_exists('price', $material->getChanges())) {
            return;
        }

        $this->record($material, (string) $material->getChanges()['price']);
    }

    private function record(Material $material, string $price): void
    {
        MaterialPriceHistory::create([
            'material_id' => $material->id,
            'price' => $price,
            'changed_by' => $material->priceChangedBy ?? Auth::id(),
            'recorded_at' => now(),
        ]);

        // Clear the one-shot attribution so a later save doesn't reuse it.
        $material->priceChangedBy = null;
    }
}
