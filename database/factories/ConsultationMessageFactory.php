<?php

namespace Database\Factories;

use App\Enums\SenderType;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultationMessage>
 */
class ConsultationMessageFactory extends Factory
{
    protected $model = ConsultationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consultation_id' => Consultation::factory(),
            'sender_type' => fake()->randomElement(SenderType::cases()),
            'message' => fake()->sentence(),
            'attachment' => null,
        ];
    }

    /**
     * Authored by the consumer side.
     */
    public function fromKonsumen(): static
    {
        return $this->state(fn () => ['sender_type' => SenderType::Konsumen]);
    }

    /**
     * Authored by the staff (manager) side.
     */
    public function fromManager(): static
    {
        return $this->state(fn () => ['sender_type' => SenderType::Manager]);
    }
}
