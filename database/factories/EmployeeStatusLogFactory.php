<?php

namespace Database\Factories;

use App\Enums\EmployeeStatusChangeType;
use App\Models\Employee;
use App\Models\EmployeeStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeStatusLog>
 */
class EmployeeStatusLogFactory extends Factory
{
    protected $model = EmployeeStatusLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'change_type' => EmployeeStatusChangeType::Salary,
            'old_value' => '150000.00',
            'new_value' => '160000.00',
            'effective_date' => now()->toDateString(),
            'created_by' => null,
        ];
    }
}
