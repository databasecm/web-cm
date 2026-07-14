<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payslip>
 */
class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payroll_id' => Payroll::factory(),
            'employee_id' => Employee::factory(),
            'days_present' => 6,
            'daily_wage' => '150000.00',
            'gross' => '900000.00',
            'deductions' => '0.00',
            'net' => '900000.00',
            'slip_file' => null,
        ];
    }
}
