<?php

namespace TraceLog\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use TraceLog\TraceLogManager;

/**
 * Provides static access to runtime Eloquent model tracing.
 *
 * @method static void watch(Model|class-string<Model>|null $model = null) Watch every model, a model class, or one persisted record.
 * @method static bool isWatching(Model $model) Determine whether a model is currently watched.
 * @method static void stop() Stop all watches registered for the current application scope.
 *
 * @see TraceLogManager
 */
class TraceLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TraceLogManager::class;
    }
}
