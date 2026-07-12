<?php

namespace Database\Factories;

use App\Enums\Bidang;
use App\Enums\EmployeeStatus;
use App\Enums\EmployeeType;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'bidang' => fake()->randomElement(Bidang::cases()),
            'type' => EmployeeType::Harian,
            'daily_wage' => '150000.00',
            'position' => fake()->randomElement(['Tukang', 'Kepala Tukang', 'Helper']),
            'status' => EmployeeStatus::Aktif,
            'managed_by' => null,
        ];
    }

    public function inBidang(Bidang $bidang): static
    {
        return $this->state(fn () => ['bidang' => $bidang]);
    }

    public function managedBy(User $mandor): static
    {
        return $this->state(fn () => ['managed_by' => $mandor->id, 'bidang' => $mandor->bidang]);
    }

    public function type(EmployeeType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function status(EmployeeStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
