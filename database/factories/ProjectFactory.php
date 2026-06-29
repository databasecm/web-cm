<?php

namespace Database\Factories;

use App\Enums\Bidang;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'konsumen_id' => fn () => User::factory()->create([
                'role_id' => Role::where('name', 'konsumen')->value('id'),
            ])->id,
            'manager_id' => null,
            'bidang' => fake()->randomElement(Bidang::cases()),
            'title' => fake()->randomElement(['Renovasi Dapur', 'Pembangunan Ruko', 'Furniture Kantor']),
            'status' => ProjectStatus::Draft,
            'progress_percent' => 0,
            'contract_value' => null,
            'payment_scheme' => null,
            'is_financed' => false,
            'bank_mitra_id' => null,
        ];
    }

    public function inBidang(Bidang $bidang): static
    {
        return $this->state(fn () => ['bidang' => $bidang]);
    }

    public function ownedBy(User $konsumen): static
    {
        return $this->state(fn () => ['konsumen_id' => $konsumen->id]);
    }

    public function managedBy(User $manager): static
    {
        return $this->state(fn () => ['manager_id' => $manager->id, 'bidang' => $manager->bidang]);
    }

    public function financedBy(User $bankMitra): static
    {
        return $this->state(fn () => ['is_financed' => true, 'bank_mitra_id' => $bankMitra->id]);
    }

    public function status(ProjectStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
