<?php

namespace App\Observers;

use App\Enums\AhsapComponentType;
use App\Models\AhsapComponent;
use App\Models\Material;
use App\Services\AhsapCalculator;

/**
 * Keeps an AHSAP consistent with its components (ADR-0004):
 *
 * - Snapshots a material component's unit_price from Material.price when it is
 *   added (or its linked material changes) — a point-in-time copy, never a live
 *   join, so the AHSAP stays stable when material prices later move.
 * - Recomputes the parent base_price whenever a component is added, edited or
 *   removed.
 */
class AhsapComponentObserver
{
    public function __construct(private AhsapCalculator $calculator) {}

    public function creating(AhsapComponent $component): void
    {
        $this->snapshotMaterialPrice($component);
    }

    public function updating(AhsapComponent $component): void
    {
        // Re-snapshot only when the linked material itself changes.
        if ($component->isDirty('material_id')) {
            $this->snapshotMaterialPrice($component);
        }
    }

    public function created(AhsapComponent $component): void
    {
        $this->recalculate($component);
    }

    public function updated(AhsapComponent $component): void
    {
        $this->recalculate($component);
    }

    public function deleted(AhsapComponent $component): void
    {
        $this->recalculate($component);
    }

    /**
     * Copy the current material price into the component as a snapshot. Only
     * applies to material components; upah/alat keep their manual unit_price.
     */
    private function snapshotMaterialPrice(AhsapComponent $component): void
    {
        if ($component->type !== AhsapComponentType::Material || $component->material_id === null) {
            return;
        }

        $material = Material::find($component->material_id);

        if ($material !== null) {
            $component->unit_price = $material->price;
        }
    }

    private function recalculate(AhsapComponent $component): void
    {
        $ahsap = $component->ahsap()->first();

        if ($ahsap !== null) {
            $this->calculator->recalculate($ahsap);
        }
    }
}
