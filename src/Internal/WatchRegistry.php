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

    /** @param list<class-string<Model>> $except */
    public function watchAll(array $except = []): WatchRules
    {
        $this->watch_all = $this->newRules();
        $this->watch_all->excludeModelClasses($except);

        return $this->watch_all;
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @param  list<class-string<Model>>  $except
     */
    public function watchClasses(array $models, array $except = []): WatchRules
    {
        $rules = $this->newRules();
        $rules->excludeModelClasses($except);

        foreach ($models as $model) {
            $this->watched_classes[$model] = $rules;
        }

        return $rules;
    }

    /** @param list<class-string<Model>> $except */
    public function watchRecord(Model $model, array $except = []): WatchRules
    {
        $rules = $this->newRules();
        $rules->excludeModelClasses($except);

        $this->watched_records[$model::class][
            $model->getConnectionName() ?? ''
        ][
            (string) $model->getKey()
        ] = $rules;

        return $rules;
    }

    public function newRules(): WatchRules
    {
        return new WatchRules($this->default_sensitive_attributes);
    }

    /** @return array{all: array|null, classes: array<string, array>, records: array<string, array<string, array<string, array>>>}|null */
    public function snapshot(): ?array
    {
        if ($this->watch_all === null && $this->watched_classes === [] && $this->watched_records === []) {
            return null;
        }

        return [
            'all' => $this->watch_all?->snapshot(),
            'classes' => array_map(static fn (WatchRules $rules): array => $rules->snapshot(), $this->watched_classes),
            'records' => array_map(
                static fn (array $connections): array => array_map(
                    static fn (array $records): array => array_map(
                        static fn (WatchRules $rules): array => $rules->snapshot(),
                        $records,
                    ),
                    $connections,
                ),
                $this->watched_records,
            ),
        ];
    }

    /** @param array<string, mixed>|null $snapshot */
    public function restore(?array $snapshot): void
    {
        $this->stop();

        if ($snapshot === null) {
            return;
        }

        if ($snapshot['all'] !== null) {
            $this->watch_all = $this->newRules();
            $this->watch_all->restore($snapshot['all']);
        }

        foreach ($snapshot['classes'] as $model => $rules_snapshot) {
            if (! is_string($model) || ! is_a($model, Model::class, true)) {
                continue;
            }

            $this->watched_classes[$model] = $this->newRules();
            $this->watched_classes[$model]->restore($rules_snapshot);
        }

        foreach ($snapshot['records'] as $model => $connections) {
            if (! is_string($model) || ! is_a($model, Model::class, true)) {
                continue;
            }

            foreach ($connections as $connection => $records) {
                foreach ($records as $key => $rules_snapshot) {
                    $this->watched_records[$model][$connection][$key] = $this->newRules();
                    $this->watched_records[$model][$connection][$key]->restore($rules_snapshot);
                }
            }
        }
    }

    public function rulesFor(Model $model): ?WatchRules
    {
        $rules = [
            $this->watched_records[$model::class][$model->getConnectionName() ?? ''][(string) $model->getKey()] ?? null,
            $this->watched_classes[$model::class] ?? null,
            $this->watch_all,
        ];

        foreach ($rules as $candidate) {
            if ($candidate?->includesModel($model::class)) {
                return $candidate;
            }
        }

        return null;
    }

    public function hasActiveWatch(): bool
    {
        return $this->watch_all !== null || $this->watched_classes !== [] || $this->watched_records !== [];
    }

    public function stop(): void
    {
        $this->watch_all = null;
        $this->watched_classes = [];
        $this->watched_records = [];
    }
}
