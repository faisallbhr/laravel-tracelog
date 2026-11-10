<?php

namespace TraceLog\Tests;

use TraceLog\Facades\TraceLog;

class TraceLogFacadeTest extends TestCase
{
    public function test_facade_can_watch_models(): void
    {
        TraceLog::watch();

        $this->assertTrue(TraceLog::isWatching(new TestModel));

        TraceLog::stop();
    }
}
