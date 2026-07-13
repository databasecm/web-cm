<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'project_id' => Project::factory(),
            'date' => now()->toDateString(),
            'status' => AttendanceStatus::Hadir,
            'recorded_by' => null,
            'note' => null,
        ];
    }

    public function status(AttendanceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['date' => $date]);
    }
}
