<?php

namespace App\Observers;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Services\AhsapReviewService;
use App\Services\MaterialPriceService;
use Illuminate\Support\Facades\Auth;

/**
 * Journals every material price point to material_price_history and flags the
 * AHSAP that use the material for review (ADR-0004).
 *
 * This observer is the SINGLE writer of both: an initial history row on create,
 * one row per change on update, plus the AHSAP staleness flag on a change.
 * {@see MaterialPriceService} is the explicit call site, but because the logic
 * lives here, a direct `$material->update(['price' => …])` is caught too — and
 * never double-acts, since one save produces at most one journal row, and
 * flagging is idempotent ({@see AhsapReviewService::flagForMaterial}).
 */
class MaterialObserver
{
    public function __construct(private AhsapReviewService $review) {}

    public function created(Material $material): void
    {
        // A brand-new material is not yet referenced by any AHSAP, so only the
        // initial price point is recorded.
        $this->record($material, (string) $material->price);
    }

    public function updated(Material $material): void
    {
        if (! array_key_exists('price', $material->getChanges())) {
            return;
        }

        $this->record($material, (string) $material->getChanges()['price']);

        // Mark dependent AHSAP stale — never edit their base_price silently.
        $this->review->flagForMaterial(
            $material->id,
            "Harga material '{$material->name}' berubah.",
        );
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
