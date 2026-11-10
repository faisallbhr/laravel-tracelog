<?php

namespace TraceLog\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use TraceLog\Concerns\Traceable;
use TraceLog\Enums\ModelEvent;
use TraceLog\TraceLogManager;

/**
 * Writes watched Eloquent events to Laravel's default log channel.
 */
class EloquentEventListener
{
    public function __construct(
        private readonly TraceLogManager $manager
    ) {}

    /**
     * Log a model event when the model is watched or uses the Traceable trait.
     */
    public function handle(ModelEvent $event, Model $model): void
    {
        if (
            ! $this->manager->isWatching($model)
            && ! in_array(Traceable::class, class_uses_recursive($model), true)
        ) {
            return;
        }

        if ($this->shouldSkipEvent($event, $model)) {
            return;
        }

        $context = [
            'event' => $event->value,
            'model' => $model::class,
            'id' => $model->getKey(),
            'changes' => $this->resolveChanges($event, $model),
            'source' => $this->resolveSource(),
        ];

        Log::debug(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Suppress generic events also emitted by restore and force-delete.
     */
    private function shouldSkipEvent(ModelEvent $event, Model $model): bool
    {
        if ($event === ModelEvent::Deleted && method_exists($model, 'isForceDeleting')) {
            return $model->isForceDeleting();
        }

        if ($event !== ModelEvent::Updated || ! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }

        $deleted_at = $model->getDeletedAtColumn();

        // shortcut: a cleared deleted_at is treated as restore, revisit if manual undeletes need update logs.
        return array_key_exists($deleted_at, $model->getChanges())
            && ($model->getPrevious()[$deleted_at] ?? null) !== null
            && $model->getAttribute($deleted_at) === null;
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function resolveChanges(ModelEvent $event, Model $model): array
    {
        return match ($event) {
            ModelEvent::Created => $this->diff([], $model->getAttributes()),
            ModelEvent::Updated, ModelEvent::Restored => $this->diff($model->getPrevious(), $model->getChanges()),
            ModelEvent::Deleted, ModelEvent::ForceDeleted => $this->diff($model->getAttributes(), []),
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{old: mixed, new: mixed}>
     */
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

    /**
     * Find the first application stack frame in a clickable file:line format.
     */
    private function resolveSource(): ?string
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
