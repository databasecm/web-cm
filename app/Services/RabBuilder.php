<?php

namespace App\Services;

use App\Enums\RabStatus;
use App\Models\Ahsap;
use App\Models\Project;
use App\Models\Rab;
use App\Models\RabItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Builds a new RAB version from AHSAP (ADR-0004 layer 2 / ADR-0007).
 *
 * Each item SNAPSHOTS its description/unit/unit_price from the source AHSAP's
 * current base_price — never a live join — so an AHSAP resync can never move an
 * existing RAB; a fresh build (new version) picks up the latest prices. The
 * margin/PPN/overhead rates are taken from {@see SettingService} (overridable
 * per build) and snapshotted onto the RAB, so changing the global defaults later
 * leaves existing RABs untouched. All money maths use BigDecimal (ADR-0005).
 */
class RabBuilder
{
    public function __construct(private SettingService $settings) {}

    /**
     * @param  array<int, array{ahsap_id?: int|null, volume?: float|string, description?: string|null, unit?: string|null, unit_price?: float|string}>  $items
     * @param  array{margin_percent?: float|string, ppn_percent?: float|string, overhead_percent?: float|string}  $rateOverrides
     */
    public function build(Project $project, array $items, array $rateOverrides = []): Rab
    {
        $marginPercent = (string) ($rateOverrides['margin_percent'] ?? $this->settings->marginPercentDefault());
        $ppnPercent = (string) ($rateOverrides['ppn_percent'] ?? $this->settings->ppnPercentDefault());
        $overheadPercent = (string) ($rateOverrides['overhead_percent'] ?? $this->settings->overheadPercentDefault());

        return DB::transaction(function () use ($project, $items, $marginPercent, $ppnPercent, $overheadPercent): Rab {
            $version = (int) $project->rabs()->max('version') + 1;

            $rab = Rab::create([
                'project_id' => $project->id,
                'version' => $version,
                'overhead_percent' => $overheadPercent,
                'margin_percent' => $marginPercent,
                'ppn_percent' => $ppnPercent,
                'status' => RabStatus::Draft,
            ]);

            $totalMaterial = BigDecimal::zero();
            $totalUpah = BigDecimal::zero();

            foreach ($items as $item) {
                [$line, $material, $upah] = $this->buildItem($rab, $item);
                $totalMaterial = $totalMaterial->plus($material);
                $totalUpah = $totalUpah->plus($upah);
                unset($line);
            }

            $this->applyTotals($rab, $totalMaterial, $totalUpah, $overheadPercent, $marginPercent, $ppnPercent);

            return $rab->refresh();
        });
    }

    /**
     * Create one RAB item, snapshotting from AHSAP when given, and return its
     * material/upah contributions.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: RabItem, 1: BigDecimal, 2: BigDecimal}
     */
    private function buildItem(Rab $rab, array $item): array
    {
        $volume = BigDecimal::of((string) ($item['volume'] ?? '0'));
        $ahsapId = $item['ahsap_id'] ?? null;
        $ahsap = $ahsapId !== null ? Ahsap::find($ahsapId) : null;

        if ($ahsap !== null) {
            $description = $ahsap->name;
            $unit = $ahsap->unit;
            $unitPrice = BigDecimal::of((string) $ahsap->base_price);
            $breakdown = $ahsap->costBreakdown();
            $materialPer = BigDecimal::of($breakdown['material']);
            $upahPer = BigDecimal::of($breakdown['upah']);
        } else {
            // Manual line — treated entirely as material.
            $description = (string) ($item['description'] ?? '');
            $unit = $item['unit'] ?? null;
            $unitPrice = BigDecimal::of((string) ($item['unit_price'] ?? '0'));
            $materialPer = $unitPrice;
            $upahPer = BigDecimal::zero();
        }

        $subtotal = $volume->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);

        $rabItem = RabItem::create([
            'rab_id' => $rab->id,
            'ahsap_id' => $ahsapId,
            'description' => $description,
            'unit' => $unit,
            'volume' => (string) $volume,
            'unit_price' => (string) $unitPrice->toScale(2, RoundingMode::HALF_UP),
            'subtotal' => (string) $subtotal,
        ]);

        return [
            $rabItem,
            $volume->multipliedBy($materialPer)->toScale(2, RoundingMode::HALF_UP),
            $volume->multipliedBy($upahPer)->toScale(2, RoundingMode::HALF_UP),
        ];
    }

    /**
     * Compute and persist the RAB totals, progressively stacking overhead →
     * margin → PPN (BigDecimal, HALF_UP):
     *   base        = total_material + total_upah
     *   overhead    = base × overhead%
     *   margin      = (base + overhead) × margin%
     *   ppn         = (base + overhead + margin) × ppn%
     *   grand_total = base + overhead + margin + ppn
     */
    private function applyTotals(
        Rab $rab,
        BigDecimal $totalMaterial,
        BigDecimal $totalUpah,
        string $overheadPercent,
        string $marginPercent,
        string $ppnPercent,
    ): void {
        $base = $totalMaterial->plus($totalUpah);
        $overhead = $this->percentOf($base, $overheadPercent);
        $margin = $this->percentOf($base->plus($overhead), $marginPercent);
        $ppn = $this->percentOf($base->plus($overhead)->plus($margin), $ppnPercent);
        $grandTotal = $base->plus($overhead)->plus($margin)->plus($ppn);

        $rab->update([
            'total_material' => (string) $totalMaterial->toScale(2, RoundingMode::HALF_UP),
            'total_upah' => (string) $totalUpah->toScale(2, RoundingMode::HALF_UP),
            'overhead' => (string) $overhead,
            'margin' => (string) $margin,
            'ppn' => (string) $ppn,
            'grand_total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
        ]);
    }

    private function percentOf(BigDecimal $amount, string $percent): BigDecimal
    {
        return $amount->multipliedBy(BigDecimal::of($percent)->dividedBy('100', 10, RoundingMode::HALF_UP))
            ->toScale(2, RoundingMode::HALF_UP);
    }
}
