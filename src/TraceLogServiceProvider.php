<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TraceLog\Enums\ModelEvent;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\WatchRegistry;
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
        $this->app->scoped(WatchRegistry::class);
        $this->app->scoped(TraceContext::class);
        $this->app->scoped(TraceLogManager::class);
        $this->app->scoped(EloquentEventListener::class);
    }

    /**
     * Listen for supported events from every Eloquent model.
     */
    public function boot(): void
    {
        foreach (ModelEvent::cases() as $event) {
            Event::listen(
                "eloquent.{$event->value}: *",
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
