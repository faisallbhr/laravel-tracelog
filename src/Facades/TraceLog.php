<?php

namespace TraceLog\Facades;

use Illuminate\Support\Facades\Facade;
use TraceLog\TraceLogManager;

/**
 * @method static void watch(\Illuminate\Database\Eloquent\Model|string|null $model = null)
 * @method static bool isWatching(\Illuminate\Database\Eloquent\Model $model)
 * @method static void stop()
 */
class TraceLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TraceLogManager::class;
    }
}
