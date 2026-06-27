<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialPriceHistory>
 */
class MaterialPriceHistoryFactory extends Factory
{
    protected $model = MaterialPriceHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'price' => fake()->numberBetween(10_000, 500_000),
            'changed_by' => null,
            'recorded_at' => now(),
        ];
    }
}
