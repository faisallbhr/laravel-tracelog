<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TraceLog\Listeners\EloquentEventListener;

/**
 * Registers TraceLog services and Eloquent event listeners with Laravel.
 */
class TraceLogServiceProvider extends ServiceProvider
{
    /**
     * Register tracing services for the current application scope.
     */
    public function register(): void
    {
        $this->app->scoped(TraceLogManager::class, fn () => new TraceLogManager);
        $this->app->scoped(EloquentEventListener::class);
    }

    /**
     * Listen for created, updated, and deleted events from every Eloquent model.
     */
    public function boot(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen(
                "eloquent.{$event}: *",
                function (string $event_name, array $payload) use ($event): void {
                    $model = $payload[0] ?? null;

                    if (! $model instanceof Model) {
                        return;
                    }

                    app(EloquentEventListener::class)->handle($event, $model);
                }
            );
        }
    }
}
