<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TraceLog\Internal\WatchRegistry;

/**
 * Tracks which Eloquent models should emit trace logs in the current scope.
 */
class TraceLogManager
{
    private readonly WatchRegistry $registry;

    public function __construct(?WatchRegistry $registry = null)
    {
        $this->registry = $registry ?? new WatchRegistry;
    }

    /**
     * Watch every model, all records of a model class, or one persisted record.
     *
     * Calling this method without an argument watches every Eloquent model.
     *
     * @param  Model|class-string<Model>|null  $model
     *
     * @throws InvalidArgumentException When the class is not an Eloquent model or the model is unsaved.
     */
    public function watch(Model|string|null $model = null): WatchConfiguration
    {
        if ($model === null) {
            return new WatchConfiguration($this->registry->watchAll());
        }

        if (is_string($model) && ! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException('Expected an Eloquent model class name, got: '.$model);
        }

        if (is_string($model)) {
            return new WatchConfiguration($this->registry->watchClass($model));
        }

        if ($model->getKey() === null) {
            throw new InvalidArgumentException('Cannot watch an unsaved Eloquent model.');
        }

        return new WatchConfiguration($this->registry->watchRecord($model));
    }

    /**
     * Determine whether the given model is covered by an active watch.
     */
    public function isWatching(Model $model): bool
    {
        return $this->registry->rulesFor($model) !== null;
    }

    /**
     * Stop all model, class, and global watches in the current scope.
     */
    public function stop(): void
    {
        $this->registry->stop();
    }
}
