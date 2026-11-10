<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Tracks which Eloquent models should emit trace logs in the current scope.
 */
class TraceLogManager
{
    private ?WatchConfiguration $watch_all = null;

    /** @var array<class-string<Model>, WatchConfiguration> */
    private array $watched_classes = [];

    /** @var array<class-string<Model>, array<string, WatchConfiguration>> */
    private array $watched_records = [];

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
            return $this->watch_all = new WatchConfiguration;
        }

        if (is_string($model) && ! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException('Expected an Eloquent model class name, got: '.$model);
        }

        if (is_string($model)) {
            return $this->watched_classes[$model] = new WatchConfiguration;
        }

        if ($model->getKey() === null) {
            throw new InvalidArgumentException('Cannot watch an unsaved Eloquent model.');
        }

        return $this->watched_records[$model::class][
            (string) $model->getKey()
        ] = new WatchConfiguration;
    }

    /**
     * Determine whether the given model is covered by an active watch.
     */
    public function isWatching(Model $model): bool
    {
        return $this->configurationFor($model) !== null;
    }

    /**
     * Resolve the most specific watch configuration for a model.
     */
    public function configurationFor(Model $model): ?WatchConfiguration
    {
        return $this->watched_records[$model::class][(string) $model->getKey()]
            ?? $this->watched_classes[$model::class]
            ?? $this->watch_all;
    }

    /**
     * Stop all model, class, and global watches in the current scope.
     */
    public function stop(): void
    {
        $this->watch_all = null;
        $this->watched_classes = [];
        $this->watched_records = [];
    }
}
