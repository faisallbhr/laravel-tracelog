<?php

namespace TraceLog\Listeners;

use Illuminate\Database\Eloquent\Model;
use TraceLog\Concerns\Traceable;
use TraceLog\Enums\ModelEvent;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\TraceWriter;
use TraceLog\Internal\WatchRegistry;
use WeakMap;

/**
 * Writes watched Eloquent events through the configured trace writer.
 */
class EloquentEventListener
{
    /** @var WeakMap<Model, array<string, mixed>> */
    private WeakMap $previous;

    public function __construct(
        private readonly WatchRegistry $registry,
        private readonly TraceContext $trace_context,
        private readonly TraceWriter $writer
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

        $rules ??= $this->registry->newRules();
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

        $this->writer->write($context);
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
            $old_value = $old[$attribute] ?? null;
            $new_value = $new[$attribute] ?? null;

            if ($old_value === null && $new_value === null) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $old_value,
                'new' => $new_value,
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

            if ($file === null || str_contains($file, '://')) {
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
