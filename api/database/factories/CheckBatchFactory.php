<?php

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Enums\CheckType;
use App\Models\CheckBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckBatch>
 */
class CheckBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CheckType::Health,
            'status' => BatchStatus::Processing,
            'filename' => 'domains.csv',
            'total' => 0,
            'options' => ['all_records' => false],
            'seq' => 0,
        ];
    }

    /**
     * Indicate that the batch runs provider detection.
     */
    public function provider(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CheckType::Provider,
        ]);
    }
}
