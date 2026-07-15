<?php

namespace App\Services;

use App\Enums\MaterialSource;
use App\Models\Material;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Field material catalog input (Fase 6-5b). A Mandor adds a material they found
 * or bought ad-hoc in the field so the Material DB (catalog) stays complete.
 *
 * SCOPE: catalog only. This NEVER touches the cash book — a Mandor has no cash
 * access, and the only path for a material expense stays a PO received by
 * Finance/O-D (Fase 6-5). The initial price is journalled to
 * material_price_history by MaterialObserver (the single price writer), attributed
 * to the Mandor via the transient priceChangedBy; price changes afterward still go
 * only through MaterialPriceService.
 */
class MaterialService
{
    /**
     * Add a field material to the catalog, attributed to the Mandor. source is
     * forced to internal; input_by records who entered it. No transaction is
     * ever posted.
     *
     * @param  array{name: string, brand?: ?string, unit?: ?string, price: string|float, spec?: ?string, is_sni?: ?bool, supplier_name?: ?string, supplier_address?: ?string}  $attrs
     */
    public function addFromField(User $mandor, array $attrs): Material
    {
        $price = (string) BigDecimal::of((string) $attrs['price'])->toScale(2, RoundingMode::HALF_UP);

        $material = new Material([
            'name' => $attrs['name'],
            'brand' => $attrs['brand'] ?? null,
            'unit' => $attrs['unit'] ?? null,
            'price' => $price,
            'spec' => $attrs['spec'] ?? null,
            'is_sni' => (bool) ($attrs['is_sni'] ?? false),
            'supplier_name' => $attrs['supplier_name'] ?? null,
            'supplier_address' => $attrs['supplier_address'] ?? null,
        ]);
        $material->source = MaterialSource::Internal->value;
        $material->input_by = $mandor->id;
        // Attribute the initial price-history point (written by MaterialObserver)
        // to the Mandor rather than to any authenticated web session.
        $material->priceChangedBy = $mandor->id;
        $material->save();

        return $material;
    }
}
