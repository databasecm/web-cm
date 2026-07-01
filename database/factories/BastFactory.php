<?php

namespace Database\Factories;

use App\Enums\BastStatus;
use App\Models\Bast;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bast>
 */
class BastFactory extends Factory
{
    protected $model = Bast::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'file' => null,
            'signed_customer' => false,
            'signed_company' => false,
            'signed_at' => null,
            'status' => BastStatus::Draft,
        ];
    }

    public function signedByCustomer(): static
    {
        return $this->state(fn () => ['signed_customer' => true]);
    }

    public function signedByCompany(): static
    {
        return $this->state(fn () => ['signed_company' => true]);
    }

    /**
     * A fully-signed BAST (both parties). Satisfies the model invariant, so the
     * saving guard also stamps signed_at.
     */
    public function signed(): static
    {
        return $this->state(fn () => [
            'signed_customer' => true,
            'signed_company' => true,
            'status' => BastStatus::Signed,
        ]);
    }
}
