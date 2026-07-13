<?php

namespace Database\Factories;

use App\Enums\ReportMediaType;
use App\Models\DailyReport;
use App\Models\ReportMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportMedia>
 */
class ReportMediaFactory extends Factory
{
    protected $model = ReportMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'daily_report_id' => DailyReport::factory(),
            'type' => ReportMediaType::Photo,
            'file' => 'reports/photo.jpg',
            'caption' => null,
        ];
    }
}
