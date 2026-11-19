<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TraceLog\Internal\SourceResolver;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\TraceWriter;
use TraceLog\Internal\TransactionTracker;
use TraceLog\Internal\WatchRegistry;

/**
 * Tracks which Eloquent models should emit trace logs in the current scope.
 */
class TraceLogManager
{
    public function __construct(
        private readonly WatchRegistry $registry,
        private readonly TraceContext $context,
        private readonly TraceWriter $writer,
        private readonly SourceResolver $source_resolver,
        private readonly TransactionTracker $transactions,
    ) {}

    /**
     * Watch all models, selected model classes, or one persisted record,
     * optionally excluding model classes.
     *
     * Calling this method without an argument watches every Eloquent model.
     *
     * @param  Model|class-string<Model>|list<class-string<Model>>|null  $models
     * @param  list<class-string<Model>>  $except
     *
     * @throws InvalidArgumentException When the class is not an Eloquent model or the model is unsaved.
     */
    public function watch(Model|string|array|null $models = null, array $except = []): WatchConfiguration
    {
        $except = $this->validateModelClasses($except, 'exclusions');

        if ($models === null) {
            return new WatchConfiguration($this->registry->watchAll($except), $this->context);
        }

        if (is_string($models)) {
            $models = [$models];
        }

        if (is_array($models)) {
            $models = $this->validateModelClasses($models, 'models');

            return new WatchConfiguration($this->registry->watchClasses($models, $except), $this->context);
        }

        if ($models->getKey() === null) {
            throw new InvalidArgumentException('Cannot watch an unsaved Eloquent model.');
        }

        return new WatchConfiguration($this->registry->watchRecord($models, $except), $this->context);
    }

    /**
     * Determine whether the given model is covered by an active watch.
     */
    public function isWatching(Model $model): bool
    {
        return $this->registry->rulesFor($model) !== null;
    }

    /**
     * Record a trace-level business milestone for the active explicit watch.
     *
     * Labels are trimmed and must contain 1 to 200 UTF-8 characters. Calling
     * this method without an explicit watch is a no-op.
     *
     * @throws InvalidArgumentException When the label is blank or too long.
     */
    public function checkpoint(string $label): void
    {
        if (! $this->registry->hasActiveWatch()) {
            return;
        }

        $label = trim($label);
        $length = function_exists('mb_strlen')
            ? mb_strlen($label, 'UTF-8')
            : preg_match_all('/./us', $label);

        if ($label === '' || $length === false || $length > 200) {
            throw new InvalidArgumentException('TraceLog checkpoint labels must contain 1 to 200 UTF-8 characters.');
        }

        $this->writer->write([
            ...$this->context->metadata(),
            'event' => 'checkpoint',
            'label' => $label,
            'source' => $this->source_resolver->resolve(),
        ]);
    }

    /** @return array{watch: array, context: array}|null */
    public function snapshot(): ?array
    {
        $watch = $this->registry->snapshot();

        return $watch === null ? null : [
            'watch' => $watch,
            'context' => $this->context->snapshot(),
        ];
    }

    /** @param array<string, mixed>|null $snapshot */
    public function restore(?array $snapshot): void
    {
        $this->transactions->reset();
        $this->registry->restore($snapshot['watch'] ?? null);
        $this->context->restore($snapshot['context'] ?? null);
    }

    /**
     * Stop all model, class, and global watches in the current scope.
     */
    public function stop(): void
    {
        $this->transactions->reset();
        $this->registry->stop();
        $this->context->reset();
    }

    /**
     * @param  array<array-key, mixed>  $models
     * @return list<class-string<Model>>
     */
    private function validateModelClasses(array $models, string $argument): array
    {
        if (! array_is_list($models)) {
            throw new InvalidArgumentException("TraceLog watch {$argument} must be a list of Eloquent model class names.");
        }

        foreach ($models as $model) {
            if (! is_string($model) || ! is_a($model, Model::class, true)) {
                throw new InvalidArgumentException("TraceLog watch {$argument} must contain only Eloquent model class names.");
            }
        }

        return array_values(array_unique($models));
    }
}
