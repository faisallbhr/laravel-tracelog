<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TraceLog\Listeners\EloquentEventListener;

class TraceLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TraceLogManager::class, fn () => new TraceLogManager);
        $this->app->scoped(EloquentEventListener::class);
    }

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
