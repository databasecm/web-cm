<?php

namespace Database\Factories;

use App\Enums\AhsapComponentType;
use App\Models\Ahsap;
use App\Models\AhsapComponent;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AhsapComponent>
 */
class AhsapComponentFactory extends Factory
{
    protected $model = AhsapComponent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ahsap_id' => Ahsap::factory(),
            'type' => AhsapComponentType::Upah,
            'material_id' => null,
            'description' => fake()->words(2, true),
            'coefficient' => 1,
            'unit_price' => fake()->numberBetween(10_000, 100_000),
        ];
    }

    /**
     * A material component linked to the given (or a new) material. Its unit_price
     * is snapshotted from the material by the observer.
     */
    public function material(?Material $material = null): static
    {
        return $this->state(fn () => [
            'type' => AhsapComponentType::Material,
            'material_id' => ($material ?? Material::factory()->create())->id,
        ]);
    }

    public function ofType(AhsapComponentType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function coefficient(float $coefficient): static
    {
        return $this->state(fn () => ['coefficient' => $coefficient]);
    }

    public function unitPrice(float $price): static
    {
        return $this->state(fn () => ['unit_price' => $price]);
    }
}
