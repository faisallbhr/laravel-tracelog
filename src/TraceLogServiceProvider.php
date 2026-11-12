<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TraceLog\Console\InstallCommand;
use TraceLog\Enums\ModelEvent;
use TraceLog\Http\Controllers\TraceLogViewerController;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\TraceReader;
use TraceLog\Internal\TraceWriter;
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
        $this->mergeConfigFrom(__DIR__.'/../config/tracelog.php', 'tracelog');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tracelog');

        $this->app->scoped(
            WatchRegistry::class,
            fn ($app): WatchRegistry => new WatchRegistry(
                (array) $app['config']->get('tracelog.sensitive_attributes', [])
            )
        );
        $this->app->scoped(TraceContext::class);
        $this->app->scoped(TraceReader::class);
        $this->app->scoped(TraceWriter::class);
        $this->app->scoped(TraceLogManager::class);
        $this->app->scoped(EloquentEventListener::class);
    }

    /**
     * Listen for supported events from every Eloquent model.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([
                __DIR__.'/../config/tracelog.php' => config_path('tracelog.php'),
            ], 'tracelog-config');
        }

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

        if ((bool) config('tracelog.viewer.enabled', false)) {
            $this->registerViewerRoutes();
        }

        $this->registerQueueHooks();
    }

    private function registerViewerRoutes(): void
    {
        $path = trim((string) config('tracelog.viewer.path', 'tracelog'), '/');
        $middleware = (array) config('tracelog.viewer.middleware', ['web', 'auth']);

        Route::middleware($middleware)->prefix($path)->group(function (): void {
            Route::get('/', [TraceLogViewerController::class, 'page'])->name('tracelog.viewer');
            Route::get('/assets/viewer.css', [TraceLogViewerController::class, 'css'])->name('tracelog.viewer.css');
            Route::get('/assets/viewer.js', [TraceLogViewerController::class, 'js'])->name('tracelog.viewer.js');
            Route::get('/api/dates', [TraceLogViewerController::class, 'dates'])->name('tracelog.api.dates');
            Route::get('/api/traces', [TraceLogViewerController::class, 'traces'])->name('tracelog.api.traces');
            Route::get('/api/traces/{trace_id}', [TraceLogViewerController::class, 'trace'])->name('tracelog.api.trace');
        });
    }

    private function registerQueueHooks(): void
    {
        if (! (bool) config('tracelog.queue.propagate_watch', true) || ! class_exists(Queue::class)) {
            return;
        }

        Queue::createPayloadUsing(function (): array {
            $snapshot = app(TraceLogManager::class)->snapshot();

            return $snapshot === null ? [] : ['tracelog' => $snapshot];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($event->connectionName === 'sync') {
                return;
            }

            $snapshot = $event->job->payload()['tracelog'] ?? null;

            app(TraceLogManager::class)->restore(is_array($snapshot) ? $snapshot : null);
        });

        $reset = static function (JobProcessed|JobExceptionOccurred $event): void {
            if ($event->connectionName !== 'sync') {
                app(TraceLogManager::class)->stop();
            }
        };

        Event::listen(JobProcessed::class, $reset);
        Event::listen(JobExceptionOccurred::class, $reset);
    }
}
