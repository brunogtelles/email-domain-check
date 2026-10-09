<?php

namespace Tests\Feature;

use App\Enums\ItemStatus;
use App\Jobs\ProcessCheckItems;
use App\Models\CheckBatch;
use App\Models\CheckItem;
use App\Services\DomainCheck\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use React\Dns\Model\Message;
use Tests\Fakes\FakeResolver;
use Tests\TestCase;

class CheckBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_creates_queued_items_and_dispatches_chunked_jobs(): void
    {
        Queue::fake();
        config(['domaincheck.bulk.chunk_size' => 2]);

        $file = UploadedFile::fake()->createWithContent('domains.csv', "email\na@one.com\nb@one.com\ntwo.com\nthree.com\ninvalid value\n");

        $response = $this->post('/api/batches', ['type' => 'provider', 'file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'provider')
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.counts.queued', 3)
            ->assertJsonPath('data.counts.failed', 1)
            ->assertJsonPath('meta.duplicates_removed', 1);

        Queue::assertPushed(ProcessCheckItems::class, 2);

        $this->assertDatabaseHas('check_items', ['input' => 'invalid value', 'status' => 'failed', 'error' => 'Invalid domain or e-mail address']);
    }

    public function test_upload_validation(): void
    {
        $this->post('/api/batches', ['type' => 'health', 'file' => UploadedFile::fake()->create('domains.pdf', 1)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->post('/api/batches', ['type' => 'health', 'file' => UploadedFile::fake()->createWithContent('empty.txt', "\n\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'No domains']);

        config(['domaincheck.bulk.max_rows' => 2]);

        $this->post('/api/batches', ['type' => 'health', 'file' => UploadedFile::fake()->createWithContent('big.txt', "a.com\nb.com\nc.com")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'limit is 2']);
    }

    public function test_jobs_process_items_and_polling_returns_only_changes(): void
    {
        $dns = (new FakeResolver)
            ->add('one.com', Message::TYPE_MX, [['priority' => 0, 'target' => 'one-com.mail.protection.outlook.com']])
            ->add('two.com', Message::TYPE_MX, [['priority' => 1, 'target' => 'smtp.google.com']]);
        $this->app->instance(Resolver::class, $dns);

        $file = UploadedFile::fake()->createWithContent('domains.txt', "one.com\ntwo.com\n");

        // The sync queue runs the jobs during the upload request.
        $batchId = $this->post('/api/batches', ['type' => 'provider', 'file' => $file], ['Accept' => 'application/json'])->json('data.id');

        $poll = $this->getJson("/api/batches/{$batchId}?after=0")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('has_more', false);

        $items = collect($poll->json('items'))->keyBy('domain');
        $this->assertSame('microsoft', $items['one.com']['result']['provider']);
        $this->assertSame('google', $items['two.com']['result']['provider']);

        // Two inserts + (checking, completed) per item.
        $this->assertSame(6, $poll->json('cursor'));

        $this->getJson("/api/batches/{$batchId}?after=6")
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('cursor', 6);
    }

    public function test_every_item_update_gets_a_new_sequence_number(): void
    {
        $batch = CheckBatch::factory()->create(['seq' => 2, 'total' => 2]);
        $first = CheckItem::factory()->for($batch, 'batch')->create(['seq' => 1]);
        $second = CheckItem::factory()->for($batch, 'batch')->create(['seq' => 2]);

        $batch->updateItem($second, ['status' => ItemStatus::Checking]);
        $batch->updateItem($first, ['status' => ItemStatus::Checking]);

        $this->assertSame(3, $second->fresh()->seq);
        $this->assertSame(4, $first->fresh()->seq);
        $this->assertSame(4, $batch->fresh()->seq);

        $this->getJson("/api/batches/{$batch->id}?after=3")
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $first->id);
    }

    public function test_cancelling_fails_queued_items(): void
    {
        $batch = CheckBatch::factory()->create(['total' => 2]);
        CheckItem::factory()->for($batch, 'batch')->count(2)->create();

        $this->postJson("/api/batches/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.counts.failed', 2);

        $this->assertDatabaseMissing('check_items', ['status' => 'queued']);
    }
}
