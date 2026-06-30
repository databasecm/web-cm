<?php

namespace Database\Factories;

use App\Enums\DueCondition;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    protected $model = Installment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'term_no' => 1,
            'label' => 'DP',
            'percentage' => '100',
            'amount' => '0',
            'due_condition' => DueCondition::Checkout,
            'status' => InstallmentStatus::Locked,
        ];
    }
}
