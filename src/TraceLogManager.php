<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Tracks which Eloquent models should emit trace logs in the current scope.
 */
class TraceLogManager
{
    private bool $watch_all = false;

    /** @var array<class-string<Model>, true> */
    private array $watched_classes = [];

    /** @var array<class-string<Model>, array<string, true>> */
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
    public function watch(Model|string|null $model = null): void
    {
        if ($model === null) {
            $this->watch_all = true;

            return;
        }

        if (is_string($model)) {
            if (! is_a($model, Model::class, true)) {
                throw new InvalidArgumentException('Expected an Eloquent model class name, got: '.$model);
            }

            $this->watched_classes[$model] = true;

            return;
        }

        if ($model->getKey() === null) {
            throw new InvalidArgumentException('Cannot watch an unsaved Eloquent model.');
        }

        $this->watched_records[$model::class][
            (string) $model->getKey()
        ] = true;
    }

    /**
     * Determine whether the given model is covered by an active watch.
     */
    public function isWatching(Model $model): bool
    {
        if ($this->watch_all) {
            return true;
        }

        if (isset($this->watched_classes[$model::class])) {
            return true;
        }

        return isset($this->watched_records[$model::class][
            (string) $model->getKey()
        ]);
    }

    /**
     * Stop all model, class, and global watches in the current scope.
     */
    public function stop(): void
    {
        $this->watch_all = false;
        $this->watched_classes = [];
        $this->watched_records = [];
    }
}
