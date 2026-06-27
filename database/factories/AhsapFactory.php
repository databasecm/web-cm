<?php

namespace Database\Factories;

use App\Enums\Bidang;
use App\Models\Ahsap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ahsap>
 */
class AhsapFactory extends Factory
{
    protected $model = Ahsap::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'AHS.'.fake()->unique()->numerify('####'),
            'name' => fake()->randomElement([
                'Pasang dinding bata merah',
                'Plesteran 1:4',
                'Pekerjaan kusen aluminium',
                'Pengecatan tembok',
            ]),
            'bidang' => fake()->randomElement(Bidang::cases()),
            'unit' => fake()->randomElement(['m²', 'm³', 'm¹', 'titik', 'unit']),
            'base_price' => 0,
        ];
    }

    public function inBidang(Bidang $bidang): static
    {
        return $this->state(fn () => ['bidang' => $bidang]);
    }
}
