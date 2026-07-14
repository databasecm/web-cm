<?php

namespace Database\Factories;

use App\Enums\PayrollStatus;
use App\Enums\PayrollType;
use App\Models\Payroll;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payroll>
 */
class PayrollFactory extends Factory
{
    protected $model = Payroll::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_start' => '2026-07-06', // Monday
            'period_end' => '2026-07-11',   // Saturday
            'type' => PayrollType::WeeklyDaily,
            'status' => PayrollStatus::Draft,
        ];
    }

    public function status(PayrollStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
