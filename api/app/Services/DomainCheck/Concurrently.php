<?php

namespace App\Services\DomainCheck;

use React\Promise\PromiseInterface;

use function React\Async\async;
use function React\Async\await;
use function React\Promise\all;

/**
 * Small helpers to run work concurrently on the ReactPHP event loop using fibers.
 *
 * Inside a task, calling await() suspends only that task, so DNS queries from
 * many tasks are in flight at the same time.
 */
final class Concurrently
{
    /**
     * Run all tasks at once and wait for every result. Keys are preserved.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, callable(): mixed|PromiseInterface<mixed>>  $tasks
     * @return array<TKey, mixed>
     */
    public static function run(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $promises = array_map(
            fn (callable|PromiseInterface $task): PromiseInterface => $task instanceof PromiseInterface ? $task : async($task)(),
            $tasks,
        );

        return await(all($promises));
    }

    /**
     * Process items with at most $limit handlers running at the same time.
     *
     * @template TItem
     *
     * @param  iterable<TItem>  $items
     * @param  callable(TItem): void  $handler
     */
    public static function each(iterable $items, int $limit, callable $handler): void
    {
        $queue = is_array($items) ? array_values($items) : iterator_to_array($items, false);
        $workers = [];

        for ($i = 0; $i < min(max(1, $limit), count($queue)); $i++) {
            $workers[] = async(function () use (&$queue, $handler): void {
                while ($queue !== []) {
                    $handler(array_shift($queue));
                }
            })();
        }

        if ($workers !== []) {
            await(all($workers));
        }
    }
}
