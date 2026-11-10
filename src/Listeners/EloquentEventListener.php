<?php

namespace TraceLog\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use TraceLog\Concerns\Traceable;
use TraceLog\TraceLogManager;

class EloquentEventListener
{
    public function __construct(
        private readonly TraceLogManager $manager
    ) {}

    public function handle(string $event, Model $model): void
    {
        if (
            ! $this->manager->isWatching($model)
            && ! in_array(Traceable::class, class_uses_recursive($model), true)
        ) {
            return;
        }

        $context = [
            'event' => $event,
            'model' => $model::class,
            'id' => $model->getKey(),
            'changes' => $this->resolveChanges($event, $model),
            'origin' => $this->resolveOrigin(),
        ];

        Log::debug(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function resolveChanges(string $event, Model $model): array
    {
        return match ($event) {
            'created' => $this->diff([], $model->getAttributes()),
            'updated' => $this->diff($model->getPrevious(), $model->getChanges()),
            'deleted' => $this->diff($model->getAttributes(), []),
            default => [],
        };
    }

    private function diff(array $old, array $new): array
    {
        $changes = [];

        foreach (array_keys($old + $new) as $attribute) {
            $changes[$attribute] = [
                'old' => $old[$attribute] ?? null,
                'new' => $new[$attribute] ?? null,
            ];
        }

        return $changes;
    }

    private function resolveOrigin(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            if (str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_starts_with($file, dirname(__DIR__).DIRECTORY_SEPARATOR)) {
                continue;
            }

            return isset($frame['line']) ? $file.':'.$frame['line'] : $file;
        }

        return null;
    }
}
