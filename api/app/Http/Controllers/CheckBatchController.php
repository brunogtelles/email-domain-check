<?php

namespace App\Http\Controllers;

use App\Enums\BatchStatus;
use App\Enums\CheckType;
use App\Enums\ItemStatus;
use App\Http\Requests\StoreCheckBatchRequest;
use App\Http\Resources\CheckBatchResource;
use App\Http\Resources\CheckItemResource;
use App\Jobs\ProcessCheckItems;
use App\Models\CheckBatch;
use App\Models\CheckItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckBatchController extends Controller
{
    /**
     * Create a bulk job from an uploaded CSV / TXT file and queue it.
     */
    public function store(StoreCheckBatchRequest $request): JsonResponse
    {
        ['entries' => $entries, 'duplicates' => $duplicates] = $request->parsedEntries();

        $batch = DB::transaction(function () use ($request, $entries): CheckBatch {
            $batch = CheckBatch::create([
                'type' => $request->enum('type', CheckType::class),
                'status' => BatchStatus::Processing,
                'filename' => $request->file('file')->getClientOriginalName(),
                'total' => count($entries),
                'options' => [
                    'all_records' => $request->boolean('all_records'),
                    'selector' => $request->input('selector'),
                ],
                'seq' => count($entries),
            ]);

            $now = now();

            foreach (array_chunk($entries, 500, true) as $chunk) {
                CheckItem::insert(array_map(fn (array $entry, int $index): array => [
                    'check_batch_id' => $batch->id,
                    'input' => $entry['input'],
                    'domain' => $entry['domain'],
                    'selector' => $entry['selector'],
                    'status' => $entry['domain'] === null ? ItemStatus::Failed->value : ItemStatus::Queued->value,
                    'error' => $entry['domain'] === null ? 'Invalid domain or e-mail address' : null,
                    'seq' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk, array_keys($chunk)));
            }

            return $batch;
        });

        $batch->items()
            ->where('status', ItemStatus::Queued)
            ->orderBy('id')
            ->pluck('id')
            ->chunk((int) config('domaincheck.bulk.chunk_size'))
            ->each(fn ($ids) => ProcessCheckItems::dispatch($batch->id, $ids->values()->all()));

        $batch->syncCompletion();

        return (new CheckBatchResource($batch))
            ->additional(['meta' => ['duplicates_removed' => $duplicates]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Poll a batch: summary + every item that changed after the given cursor.
     */
    public function show(Request $request, CheckBatch $batch): JsonResponse
    {
        $after = max(0, (int) $request->query('after', '0'));
        $pageSize = (int) config('domaincheck.bulk.poll_page_size');

        $batch->syncCompletion();

        $items = $batch->items()
            ->where('seq', '>', $after)
            ->orderBy('seq')
            ->limit($pageSize)
            ->get();

        return (new CheckBatchResource($batch))
            ->additional([
                'items' => CheckItemResource::collection($items),
                'cursor' => $items->last()->seq ?? $after,
                'has_more' => $items->count() === $pageSize,
            ])
            ->response();
    }

    /**
     * Stop a batch: queued items are marked as failed and running jobs stop picking new items.
     */
    public function cancel(CheckBatch $batch): CheckBatchResource
    {
        if ($batch->status === BatchStatus::Processing) {
            $batch->update(['status' => BatchStatus::Cancelled, 'finished_at' => now()]);

            $batch->items()
                ->where('status', ItemStatus::Queued)
                ->lazyById()
                ->each(fn (CheckItem $item) => $batch->updateItem($item, ['status' => ItemStatus::Failed, 'error' => 'Cancelled']));
        }

        return new CheckBatchResource($batch);
    }
}
