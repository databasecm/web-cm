<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Project;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.fake()->unique()->numerify('######'),
            'project_id' => Project::factory(),
            'supplier_id' => null,
            'status' => PurchaseOrderStatus::Draft,
            'total' => '0.00',
            'note' => null,
            'ordered_by' => null,
            'received_by' => null,
            'received_at' => null,
        ];
    }

    public function status(PurchaseOrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }
}
