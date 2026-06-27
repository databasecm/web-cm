<?php

namespace App\Services;

use App\Enums\AhsapComponentType;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AHSAP staleness flagging and the explicit resync (ADR-0004, Fase 2A-3).
 *
 * A material price change only FLAGS the AHSAP that use it — base_price is never
 * touched silently. Flagging is idempotent: an already-flagged AHSAP is left as
 * is (reason/timestamp not re-stamped), so one price change = one flag even if
 * the service and observer both run. The resync is the deliberate, audited action
 * that pulls current prices into the components and recomputes base_price.
 */
class AhsapReviewService
{
    public function __construct(private AhsapCalculator $calculator) {}

    /**
     * Flag every AHSAP that has a material component referencing the given
     * material. Returns the number newly flagged. Idempotent: AHSAP already
     * flagged are skipped (the `where needs_review = false` guard), and the bulk
     * query writes no model events, so no audit noise.
     */
    public function flagForMaterial(int $materialId, string $reason): int
    {
        $ahsapIds = AhsapComponent::query()
            ->where('material_id', $materialId)
            ->distinct()
            ->pluck('ahsap_id');

        if ($ahsapIds->isEmpty()) {
            return 0;
        }

        return Ahsap::query()
            ->whereIn('id', $ahsapIds)
            ->where('needs_review', false)
            ->update([
                'needs_review' => true,
                'review_reason' => $reason,
                'review_requested_at' => now(),
            ]);
    }

    /**
     * Resync an AHSAP: pull current Material.price into each material component,
     * recompute base_price (BigDecimal), clear the review flag, and write one
     * audit row — this is a deliberate base_price change (CLAUDE.md §6.6).
     */
    public function resync(Ahsap $ahsap, ?User $by = null): Ahsap
    {
        $before = (string) $ahsap->base_price;

        DB::transaction(function () use ($ahsap): void {
            foreach ($ahsap->components()->where('type', AhsapComponentType::Material->value)->get() as $component) {
                $material = Material::find($component->material_id);

                if ($material !== null) {
                    // Quiet: we recompute once below, not per component.
                    $component->unit_price = $material->price;
                    $component->saveQuietly();
                }
            }

            $this->calculator->recalculate($ahsap); // quiet base_price write

            $ahsap->forceFill([
                'needs_review' => false,
                'review_reason' => null,
                'review_requested_at' => null,
            ])->saveQuietly();
        });

        AuditLog::create([
            'user_id' => $by?->id ?? Auth::id(),
            'action' => 'ahsap_resynced',
            'entity' => Ahsap::class,
            'entity_id' => $ahsap->id,
            'before' => ['base_price' => $before, 'needs_review' => true],
            'after' => ['base_price' => (string) $ahsap->base_price, 'needs_review' => false],
            'ip' => request()->ip(),
        ]);

        return $ahsap;
    }
}
