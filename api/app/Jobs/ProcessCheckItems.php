<?php

namespace App\Jobs;

use App\Enums\ItemStatus;
use App\Models\CheckBatch;
use App\Models\CheckItem;
use App\Services\DomainCheck\Concurrently;
use App\Services\DomainCheck\DomainCheckRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks a chunk of a bulk upload, several domains at a time.
 *
 * Every status change is written immediately so the UI can show rows moving
 * from Queued to Checking to Completed / Failed while the chunk is still running.
 */
class ProcessCheckItems implements ShouldQueue
{
    use Queueable;

    /**
     * Items are processed at most once: a retry would re-check finished domains.
     */
    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $itemIds
     */
    public function __construct(
        public string $batchId,
        public array $itemIds,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DomainCheckRunner $runner): void
    {
        $batch = CheckBatch::find($this->batchId);

        if ($batch === null || $batch->isCancelled()) {
            return;
        }

        $items = CheckItem::query()
            ->whereKey($this->itemIds)
            ->where('status', ItemStatus::Queued)
            ->orderBy('id')
            ->get();

        Concurrently::each($items, (int) config('domaincheck.bulk.concurrency'), function (CheckItem $item) use ($batch, $runner): void {
            if ($batch->isCancelled()) {
                return;
            }

            $batch->updateItem($item, ['status' => ItemStatus::Checking]);

            try {
                $options = [...($batch->options ?? []), 'selectors' => array_filter([$item->selector, $batch->options['selector'] ?? null])];
                $outcome = $runner->run($batch->type, (string) $item->domain, $options);

                $batch->updateItem($item, [
                    'status' => $outcome['failure'] === null ? ItemStatus::Completed : ItemStatus::Failed,
                    'result' => $outcome['result'],
                    'error' => $outcome['failure'],
                    'duration_ms' => $outcome['duration_ms'],
                ]);
            } catch (Throwable $exception) {
                report($exception);

                $batch->updateItem($item, ['status' => ItemStatus::Failed, 'error' => 'Unexpected error: '.$exception->getMessage()]);
            }
        });

        $batch->refresh()->syncCompletion();
    }

    /**
     * Fail whatever the worker did not finish (timeout, crash...).
     */
    public function failed(?Throwable $exception): void
    {
        $batch = CheckBatch::find($this->batchId);

        if ($batch === null) {
            return;
        }

        CheckItem::query()
            ->whereKey($this->itemIds)
            ->whereIn('status', [ItemStatus::Queued, ItemStatus::Checking])
            ->lazyById()
            ->each(fn (CheckItem $item) => $batch->updateItem($item, [
                'status' => ItemStatus::Failed,
                'error' => 'Worker error: '.($exception?->getMessage() ?? 'job failed'),
            ]));

        $batch->syncCompletion();
    }
}
