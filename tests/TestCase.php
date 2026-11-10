<?php

namespace TraceLog\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TraceLog\TraceLogServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TraceLogServiceProvider::class,
        ];
    }
}
