<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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

        $this->app->scoped(WatchRegistry::class);
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
}
