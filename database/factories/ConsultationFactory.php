<?php

namespace Database\Factories;

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consultation>
 */
class ConsultationFactory extends Factory
{
    protected $model = Consultation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'konsumen_id' => null,
            'manager_id' => null,
            'bidang' => fake()->randomElement(Bidang::cases()),
            'is_guest' => false,
            'status' => ConsultationStatus::Open,
        ];
    }

    /**
     * Owned by the given consumer.
     */
    public function ownedBy(User $konsumen): static
    {
        return $this->state(fn () => ['konsumen_id' => $konsumen->id]);
    }

    /**
     * Routed to a specific business unit.
     */
    public function inBidang(Bidang $bidang): static
    {
        return $this->state(fn () => ['bidang' => $bidang]);
    }

    /**
     * Claimed by the given Manager.
     */
    public function claimedBy(User $manager): static
    {
        return $this->state(fn () => [
            'manager_id' => $manager->id,
            'bidang' => $manager->bidang,
        ]);
    }

    /**
     * In the given lifecycle state.
     */
    public function status(ConsultationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * Originated from a (now promoted) guest session.
     */
    public function guestOriginated(): static
    {
        return $this->state(fn () => ['is_guest' => true]);
    }
}
