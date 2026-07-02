<?php

namespace Database\Factories;

use App\Enums\FinancingStatus;
use App\Models\Financing;
use App\Models\FinancingStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancingStatusLog>
 */
class FinancingStatusLogFactory extends Factory
{
    protected $model = FinancingStatusLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'financing_id' => Financing::factory(),
            'status' => FinancingStatus::Submitted,
            'note' => null,
            'created_by' => null,
        ];
    }
}
