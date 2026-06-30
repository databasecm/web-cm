<?php

namespace Database\Factories;

use App\Models\Rab;
use App\Models\RabItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RabItem>
 */
class RabItemFactory extends Factory
{
    protected $model = RabItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rab_id' => Rab::factory(),
            'ahsap_id' => null,
            'description' => fake()->words(2, true),
            'unit' => 'm²',
            'volume' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
        ];
    }
}
