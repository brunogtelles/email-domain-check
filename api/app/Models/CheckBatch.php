<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\CheckType;
use App\Enums\ItemStatus;
use Database\Factories\CheckBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['type', 'status', 'filename', 'total', 'options', 'seq', 'finished_at'])]
class CheckBatch extends Model
{
    /** @use HasFactory<CheckBatchFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => CheckType::class,
            'status' => BatchStatus::class,
            'options' => 'array',
            'total' => 'integer',
            'seq' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CheckItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CheckItem::class);
    }

    /**
     * Update an item and stamp it with the batch's next sequence number.
     *
     * The increment locks the batch row until commit, so sequence numbers become
     * visible in order and pollers can fetch changes with a simple "seq > cursor".
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateItem(CheckItem $item, array $attributes): void
    {
        DB::transaction(function () use ($item, $attributes): void {
            static::query()->whereKey($this->getKey())->increment('seq');
            $sequence = (int) static::query()->whereKey($this->getKey())->value('seq');

            $item->forceFill([...$attributes, 'seq' => $sequence])->save();
        });
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = $this->items()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];

        foreach (ItemStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    public function isCancelled(): bool
    {
        return static::query()->whereKey($this->getKey())->value('status') === BatchStatus::Cancelled->value;
    }

    /**
     * Mark the batch as completed once no item is waiting or running.
     */
    public function syncCompletion(): void
    {
        if ($this->status !== BatchStatus::Processing) {
            return;
        }

        $pending = $this->items()
            ->whereIn('status', [ItemStatus::Queued, ItemStatus::Checking])
            ->exists();

        if (! $pending) {
            $this->update(['status' => BatchStatus::Completed, 'finished_at' => now()]);
        }
    }
}
