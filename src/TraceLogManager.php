<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TraceLogManager
{
    private bool $watch_all = false;

    private array $watched_classes = [];

    private array $watched_records = [];

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

    public function stop(): void
    {
        $this->watch_all = false;
        $this->watched_classes = [];
        $this->watched_records = [];
    }
}
