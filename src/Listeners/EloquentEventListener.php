<?php

namespace TraceLog\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use TraceLog\Concerns\Traceable;
use TraceLog\Enums\ModelEvent;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\WatchRegistry;
use TraceLog\Internal\WatchRules;
use WeakMap;

/**
 * Writes watched Eloquent events to Laravel's default log channel.
 */
class EloquentEventListener
{
    /** @var WeakMap<Model, array<string, mixed>> */
    private WeakMap $previous;

    public function __construct(
        private readonly WatchRegistry $registry,
        private readonly TraceContext $trace_context
    ) {
        $this->previous = new WeakMap;
    }

    /**
     * Log a model event when the model is watched or uses the Traceable trait.
     */
    public function handle(ModelEvent $event, Model $model): void
    {
        $rules = $this->registry->rulesFor($model);

        if ($rules === null && ! in_array(Traceable::class, class_uses_recursive($model), true)) {
            return;
        }

        $rules ??= new WatchRules;
        $this->capturePrevious($event, $model);

        if (! $rules->includes($event)) {
            return;
        }

        $context = [
            ...$this->trace_context->metadata(),
            'event' => $event->value,
            'model' => $model::class,
            'id' => $model->getKey(),
        ];

        $changes = $rules->filter($this->resolveChanges($event, $model));

        if ($event === ModelEvent::Updated && $changes === []) {
            return;
        }

        if ($changes !== []) {
            $context['changes'] = $changes;
        }

        $context['source'] = $this->resolveSource();

        Log::debug(json_encode(
            $context,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
        ));
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function resolveChanges(ModelEvent $event, Model $model): array
    {
        return match ($event) {
            ModelEvent::Created => $this->diff([], $model->getAttributes()),
            ModelEvent::Updated, ModelEvent::Restored => $this->diff(
                array_intersect_key($this->previous($event, $model), $model->getChanges()),
                $model->getChanges()
            ),
            ModelEvent::Deleted, ModelEvent::ForceDeleted => [],
        };
    }

    /**
     * Preserve old values that Laravel 11 discards before firing restored.
     */
    private function capturePrevious(ModelEvent $event, Model $model): void
    {
        if ($event === ModelEvent::Updated && ! method_exists($model, 'getPrevious')) {
            $this->previous[$model] = array_intersect_key($model->getRawOriginal(), $model->getChanges());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function previous(ModelEvent $event, Model $model): array
    {
        if (method_exists($model, 'getPrevious')) {
            return $model->getPrevious();
        }

        $previous = $this->previous[$model]
            ?? array_intersect_key($model->getRawOriginal(), $model->getChanges());

        if ($event === ModelEvent::Restored) {
            unset($this->previous[$model]);
        }

        return $previous;
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
