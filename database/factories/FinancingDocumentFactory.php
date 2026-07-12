<?php

namespace Database\Factories;

use App\Enums\FinancingDocumentStatus;
use App\Models\Financing;
use App\Models\FinancingDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancingDocument>
 */
class FinancingDocumentFactory extends Factory
{
    protected $model = FinancingDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'financing_id' => Financing::factory(),
            'name' => fake()->randomElement(['KTP', 'Slip Gaji', 'Rekening Koran', 'NPWP']),
            'file' => null,
            'status' => FinancingDocumentStatus::Pending,
            'note' => null,
            'uploaded_by' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    public function forFinancing(Financing $financing): static
    {
        return $this->state(fn () => ['financing_id' => $financing->id]);
    }

    public function status(FinancingDocumentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
