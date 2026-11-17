<?php

namespace TraceLog\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use TraceLog\Concerns\Traceable;
use TraceLog\Facades\TraceLog;
use TraceLog\TraceLogManager;

class ExceptionRecorderTest extends TestCase
{
    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log_path = storage_path('logs/tracelog-'.now()->format('Y-m-d').'-1.log');
        $this->clearLogs();
    }

    protected function tearDown(): void
    {
        TraceLog::stop();
        $this->clearLogs();
        parent::tearDown();
    }

    public function test_unwatched_report_is_ignored_without_allocating_trace_id(): void
    {
        report(new RuntimeException('secret value'));

        $this->assertFileDoesNotExist($this->log_path);
        $this->assertNull(app(TraceLogManager::class)->snapshot());
    }

    public function test_reported_exception_uses_trace_metadata_and_redacts_message_by_default(): void
    {
        TraceLog::watch()->tags('checkout');
        $exception = new RuntimeException('secret value');

        report($exception);

        $event = $this->events()[0];
        $this->assertSame('exception', $event['event']);
        $this->assertSame(RuntimeException::class, $event['exception']);
        $this->assertSame('[REDACTED]', $event['message']);
        $this->assertSame(['checkout'], $event['tags']);
        $this->assertSame($exception->getFile().':'.$exception->getLine(), $event['source']);
    }

    public function test_exception_after_checkpoint_keeps_order_and_trace_id(): void
    {
        TraceLog::watch()->tags('checkout');
        TraceLog::checkpoint('Before invoice');
        report(new RuntimeException('invoice failed'));

        $events = $this->events();
        $this->assertSame(['checkpoint', 'exception'], array_column($events, 'event'));
        $this->assertSame($events[0]['trace_id'], $events[1]['trace_id']);
    }

    public function test_message_can_be_explicitly_enabled(): void
    {
        config(['tracelog.exceptions.include_message' => true]);
        TraceLog::watch();

        report(new RuntimeException('visible by choice'));

        $this->assertSame('visible by choice', $this->events()[0]['message']);
    }

    public function test_same_throwable_is_recorded_once_but_distinct_throwables_are_recorded(): void
    {
        TraceLog::watch();
        $first = new RuntimeException('same');
        $second = new RuntimeException('same');

        report($first);
        report($first);
        report($second);

        $this->assertCount(2, $this->events());
    }

    public function test_stop_and_traceable_do_not_enable_exception_correlation(): void
    {
        $this->assertContains(Traceable::class, class_uses_recursive(ExceptionTraceableModel::class));
        TraceLog::watch();
        TraceLog::stop();
        report(new RuntimeException('ignored'));

        $this->assertFileDoesNotExist($this->log_path);
    }

    public function test_queue_exception_is_recorded_before_context_reset(): void
    {
        TraceLog::watch()->tags('queued');
        $job = Mockery::mock(Job::class);
        $exception = new RuntimeException('job failed');

        Event::dispatch(new JobExceptionOccurred('redis', $job, $exception));

        $this->assertSame(['queued'], $this->events()[0]['tags']);
        $this->assertNull(app(TraceLogManager::class)->snapshot());
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

class ExceptionTraceableModel
{
    use Traceable;
}
