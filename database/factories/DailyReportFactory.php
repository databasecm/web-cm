<?php

namespace Database\Factories;

use App\Models\DailyReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyReport>
 */
class DailyReportFactory extends Factory
{
    protected $model = DailyReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'mandor_id' => null,
            'date' => now()->toDateString(),
            'description' => fake()->sentence(),
            'progress_note' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    public function by(User $mandor): static
    {
        return $this->state(fn () => ['mandor_id' => $mandor->id]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }
}
