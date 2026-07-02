<?php

namespace Database\Factories;

use App\Enums\FinancingStatus;
use App\Models\Financing;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Financing>
 */
class FinancingFactory extends Factory
{
    protected $model = Financing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'konsumen_id' => fn () => User::factory()->create([
                'role_id' => Role::where('name', 'konsumen')->value('id'),
            ])->id,
            'bank_mitra_id' => fn () => User::factory()->create([
                'role_id' => Role::where('name', 'mitra_pembiayaan')->value('id'),
            ])->id,
            'amount' => '50000000.00',
            'status' => FinancingStatus::Submitted,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
            'konsumen_id' => $project->konsumen_id,
        ]);
    }

    public function forBank(User $bank): static
    {
        return $this->state(fn () => ['bank_mitra_id' => $bank->id]);
    }

    public function ownedBy(User $konsumen): static
    {
        return $this->state(fn () => ['konsumen_id' => $konsumen->id]);
    }

    public function status(FinancingStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
