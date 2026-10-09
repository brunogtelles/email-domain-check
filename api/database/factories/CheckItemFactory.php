<?php

namespace Database\Factories;

use App\Enums\ItemStatus;
use App\Models\CheckBatch;
use App\Models\CheckItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckItem>
 */
class CheckItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'check_batch_id' => CheckBatch::factory(),
            'input' => $domain,
            'domain' => $domain,
            'status' => ItemStatus::Queued,
            'seq' => 0,
        ];
    }

    /**
     * Indicate that the item finished successfully.
     *
     * @param  array<string, mixed>  $result
     */
    public function completed(array $result = []): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ItemStatus::Completed,
            'result' => $result,
            'duration_ms' => 120,
        ]);
    }
}
