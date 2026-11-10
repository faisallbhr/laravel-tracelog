<?php

namespace TraceLog\Facades;

use Illuminate\Support\Facades\Facade;
use TraceLog\TraceLogManager;

/**
 * Provides static access to runtime Eloquent model tracing.
 */
class TraceLog extends Facade
{
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return TraceLogManager::class;
    }
}
