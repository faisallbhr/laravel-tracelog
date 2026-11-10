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
        ];

        $changes = $this->resolveChanges($event, $model);

        if ($changes !== []) {
            $context['changes'] = $changes;
        }

        $context['source'] = $this->resolveSource();

        Log::debug(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Suppress the generic deleted event also emitted by force-delete.
     */
    private function shouldSkipEvent(ModelEvent $event, Model $model): bool
    {
        return $event === ModelEvent::Deleted
            && method_exists($model, 'isForceDeleting')
            && $model->isForceDeleting();
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function resolveChanges(ModelEvent $event, Model $model): array
    {
        return match ($event) {
            ModelEvent::Created => $this->diff([], $model->getAttributes()),
            ModelEvent::Updated, ModelEvent::Restored => $this->diff($model->getPrevious(), $model->getChanges()),
            ModelEvent::Deleted, ModelEvent::ForceDeleted => [],
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
