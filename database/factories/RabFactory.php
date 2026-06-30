<?php

namespace Database\Factories;

use App\Enums\RabStatus;
use App\Models\Project;
use App\Models\Rab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rab>
 */
class RabFactory extends Factory
{
    protected $model = Rab::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => 1,
            'status' => RabStatus::Draft,
        ];
    }

    public function status(RabStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
