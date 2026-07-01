<?php

namespace Database\Factories;

use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TransactionType::Income,
            'category' => TransactionCategory::PembayaranKonsumen,
            'amount' => '100000.00',
            'reference_type' => null,
            'reference_id' => null,
            'description' => null,
            'recorded_by' => null,
            'date' => now()->toDateString(),
        ];
    }
}
