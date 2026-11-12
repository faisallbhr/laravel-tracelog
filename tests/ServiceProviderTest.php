<?php

namespace TraceLog\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use TraceLog\Facades\TraceLog;
use TraceLog\TraceLogManager;
use TraceLog\TraceLogServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $this->assertNotNull(app()->getProvider(TraceLogServiceProvider::class));
    }

    public function test_install_command_is_registered(): void
    {
        $this->assertArrayHasKey('tracelog:install', Artisan::all());
    }

    public function test_queue_processing_restores_and_clears_trace_context(): void
    {
        $manager = app(TraceLogManager::class);
        TraceLog::watch()->tags('checkout');
        $snapshot = $manager->snapshot();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['tracelog' => $snapshot]);

        Event::dispatch(new JobProcessing('redis', $job));

        $this->assertSame($snapshot, $manager->snapshot());

        Event::dispatch(new JobProcessed('redis', $job));

        $this->assertNull($manager->snapshot());
    }

    public function test_queue_exception_also_clears_trace_context(): void
    {
        $manager = app(TraceLogManager::class);
        TraceLog::watch()->tags('checkout');
        $job = Mockery::mock(Job::class);

        Event::dispatch(new JobExceptionOccurred('redis', $job, new RuntimeException('failed')));

        $this->assertNull($manager->snapshot());
    }

    public function test_sync_queue_does_not_restore_a_payload_snapshot(): void
    {
        $manager = app(TraceLogManager::class);
        TraceLog::watch()->tags('current');
        $current_snapshot = $manager->snapshot();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['tracelog' => [
            'watch' => null,
            'context' => ['trace_id' => 'other123', 'tags' => ['other']],
        ]]);

        Event::dispatch(new JobProcessing('sync', $job));

        $this->assertSame($current_snapshot, $manager->snapshot());
    }
}
