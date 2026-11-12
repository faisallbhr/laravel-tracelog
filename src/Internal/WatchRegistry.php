<?php

namespace TraceLog\Internal;

use Illuminate\Database\Eloquent\Model;

/** Stores active watch rules for the current application scope. */
final class WatchRegistry
{
    /** @param list<string> $default_sensitive_attributes */
    public function __construct(
        private readonly array $default_sensitive_attributes = []
    ) {}

    private ?WatchRules $watch_all = null;

    /** @var array<class-string<Model>, WatchRules> */
    private array $watched_classes = [];

    /** @var array<class-string<Model>, array<string, array<string, WatchRules>>> */
    private array $watched_records = [];

    public function watchAll(): WatchRules
    {
        return $this->watch_all = $this->newRules();
    }

    /** @param class-string<Model> $model */
    public function watchClass(string $model): WatchRules
    {
        return $this->watched_classes[$model] = $this->newRules();
    }

    public function watchRecord(Model $model): WatchRules
    {
        return $this->watched_records[$model::class][
            $model->getConnectionName() ?? ''
        ][
            (string) $model->getKey()
        ] = $this->newRules();
    }

    public function newRules(): WatchRules
    {
        return new WatchRules($this->default_sensitive_attributes);
    }

    public function rulesFor(Model $model): ?WatchRules
    {
        return $this->watched_records[$model::class][$model->getConnectionName() ?? ''][(string) $model->getKey()]
            ?? $this->watched_classes[$model::class]
            ?? $this->watch_all;
    }

    public function stop(): void
    {
        $this->watch_all = null;
        $this->watched_classes = [];
        $this->watched_records = [];
    }
}
