<?php

namespace TraceLog\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use TraceLog\Concerns\Traceable;
use TraceLog\Facades\TraceLog;
use TraceLog\TraceLogManager;

class CheckpointTest extends TestCase
{
    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log_path = storage_path('logs/tracelog-'.now()->format('Y-m-d').'-1.log');
        $this->clearLogs();
        Schema::create('checkpoint_test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        TraceLog::stop();
        Schema::dropIfExists('checkpoint_test_models');
        $this->clearLogs();

        parent::tearDown();
    }

    public function test_inactive_checkpoint_is_a_no_op_without_allocating_trace_id(): void
    {
        TraceLog::checkpoint('ignored');

        $this->assertFileDoesNotExist($this->log_path);
        $this->assertNull(app(TraceLogManager::class)->snapshot());
    }

    public function test_checkpoint_is_logged_with_trimmed_label_tags_and_source(): void
    {
        TraceLog::watch()->tags('sales-order');

        $expected_line = __LINE__ + 1;
        TraceLog::checkpoint('  Order validated  ');

        $event = $this->events()[0];
        $this->assertSame('checkpoint', $event['event']);
        $this->assertSame('Order validated', $event['label']);
        $this->assertSame(['sales-order'], $event['tags']);
        $this->assertSame(__FILE__.':'.$expected_line, $event['source']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{8}\z/', $event['trace_id']);
    }

    public function test_checkpoint_before_model_event_shares_trace_metadata(): void
    {
        TraceLog::watch()->tags('checkout');
        TraceLog::checkpoint('Order validated');
        CheckpointTestModel::create(['name' => 'Order']);

        $events = $this->events();
        $this->assertSame(['checkpoint', 'created'], array_column($events, 'event'));
        $this->assertSame($events[0]['trace_id'], $events[1]['trace_id']);
        $this->assertSame(['checkout'], $events[1]['tags']);
    }

    public function test_class_watch_allows_trace_level_checkpoint_without_model_event(): void
    {
        TraceLog::watch(CheckpointTestModel::class);
        TraceLog::checkpoint('Class flow started');

        $this->assertSame('checkpoint', $this->events()[0]['event']);
    }

    public function test_repeated_checkpoints_keep_order_and_later_tags(): void
    {
        TraceLog::watch()->tags('first');
        TraceLog::checkpoint('Same milestone');
        TraceLog::watch()->tags('second');
        TraceLog::checkpoint('Same milestone');

        $events = $this->events();
        $this->assertSame(['Same milestone', 'Same milestone'], array_column($events, 'label'));
        $this->assertSame(['first'], $events[0]['tags']);
        $this->assertSame(['first', 'second'], $events[1]['tags']);
    }

    public function test_checkpoints_are_not_enabled_by_traceable_trait(): void
    {
        $this->assertContains(Traceable::class, class_uses_recursive(TraceableCheckpointTestModel::class));
        TraceLog::checkpoint('ignored');

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_stop_disables_subsequent_checkpoints(): void
    {
        TraceLog::watch();
        TraceLog::checkpoint('before stop');
        TraceLog::stop();
        TraceLog::checkpoint('after stop');

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame('before stop', $events[0]['label']);
    }

    public function test_queued_job_checkpoint_uses_propagated_trace_metadata(): void
    {
        TraceLog::watch()->tags('checkout');
        TraceLog::checkpoint('dispatching');
        $snapshot = app(TraceLogManager::class)->snapshot();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['tracelog' => $snapshot]);

        Event::dispatch(new JobProcessing('redis', $job));
        TraceLog::checkpoint('job started');
        Event::dispatch(new JobProcessed('redis', $job));

        $events = $this->events();
        $this->assertSame(['dispatching', 'job started'], array_column($events, 'label'));
        $this->assertSame($events[0]['trace_id'], $events[1]['trace_id']);
        $this->assertSame(['checkout'], $events[1]['tags']);
    }

    public function test_labels_are_validated_as_trimmed_utf8_text(): void
    {
        TraceLog::watch();

        foreach (['', '   ', str_repeat('🙂', 201)] as $label) {
            try {
                TraceLog::checkpoint($label);
                $this->fail('Expected invalid checkpoint label.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        TraceLog::checkpoint(' Pembayaran selesai ✅ ');
        $this->assertSame('Pembayaran selesai ✅', $this->events()[0]['label']);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        $this->assertFileExists($this->log_path);

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    private function clearLogs(): void
    {
        foreach (glob(storage_path('logs/tracelog-'.now()->format('Y-m-d').'-*.log')) ?: [] as $path) {
            unlink($path);
        }

        @unlink(storage_path('logs/tracelog.log.lock'));
    }
}

class CheckpointTestModel extends Model
{
    protected $table = 'checkpoint_test_models';

    protected $guarded = [];

    public $timestamps = false;
}

class TraceableCheckpointTestModel extends Model
{
    use Traceable;
}
