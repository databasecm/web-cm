<?php

namespace Database\Factories;

use App\Models\PoItem;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PoItem>
 */
class PoItemFactory extends Factory
{
    protected $model = PoItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'material_id' => null,
            'description' => fake()->words(3, true),
            'unit' => 'sak',
            'quantity' => '10.00',
            'unit_price' => '50000.00',
            'subtotal' => '500000.00',
        ];
    }
}
