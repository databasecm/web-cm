<?php

namespace Database\Factories;

use App\Enums\MaterialSource;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => null,
            'input_by' => null,
            'name' => fake()->randomElement(['Semen', 'Pasir', 'Besi Beton', 'Cat Tembok', 'Keramik']),
            'brand' => fake()->randomElement(['Tiga Roda', 'Holcim', 'Avian', null]),
            'unit' => fake()->randomElement(['sak', 'm³', 'batang', 'kaleng', 'dus']),
            'price' => fake()->numberBetween(10_000, 500_000),
            'spec' => null,
            'is_sni' => fake()->boolean(),
            'supplier_name' => null,
            'supplier_address' => null,
            'source' => MaterialSource::Internal,
        ];
    }

    public function priced(float $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function fromSupplier(): static
    {
        return $this->state(fn () => ['source' => MaterialSource::Supplier]);
    }
}
