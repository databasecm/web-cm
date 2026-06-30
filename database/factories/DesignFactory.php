<?php

namespace Database\Factories;

use App\Enums\DesignStatus;
use App\Models\Design;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Design>
 */
class DesignFactory extends Factory
{
    protected $model = Design::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => 1,
            'file' => null,
            'status' => DesignStatus::Draft,
            'notes' => null,
        ];
    }

    public function version(int $version): static
    {
        return $this->state(fn () => ['version' => $version]);
    }

    public function status(DesignStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => DesignStatus::Submitted]);
    }
}
