<?php

namespace TraceLog;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TraceLog\Internal\SourceResolver;
use TraceLog\Internal\TraceContext;
use TraceLog\Internal\TraceWriter;
use TraceLog\Internal\WatchRegistry;

/**
 * Tracks which Eloquent models should emit trace logs in the current scope.
 */
class TraceLogManager
{
    private readonly WatchRegistry $registry;

    private readonly TraceContext $context;

    private readonly ?TraceWriter $writer;

    private readonly ?SourceResolver $source_resolver;

    public function __construct(
        ?WatchRegistry $registry = null,
        ?TraceContext $context = null,
        ?TraceWriter $writer = null,
        ?SourceResolver $source_resolver = null,
    ) {
        $this->registry = $registry ?? new WatchRegistry;
        $this->context = $context ?? new TraceContext;
        $this->writer = $writer;
        $this->source_resolver = $source_resolver;
    }

    /**
     * Watch every model, all records of a model class, or one persisted record.
     *
     * Calling this method without an argument watches every Eloquent model.
     *
     * @param  Model|class-string<Model>|null  $model
     *
     * @throws InvalidArgumentException When the class is not an Eloquent model or the model is unsaved.
     */
    public function watch(Model|string|null $model = null): WatchConfiguration
    {
        if ($model === null) {
            return new WatchConfiguration($this->registry->watchAll(), $this->context);
        }

        if (is_string($model) && ! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException('Expected an Eloquent model class name, got: '.$model);
        }

        if (is_string($model)) {
            return new WatchConfiguration($this->registry->watchClass($model), $this->context);
        }

        if ($model->getKey() === null) {
            throw new InvalidArgumentException('Cannot watch an unsaved Eloquent model.');
        }

        return new WatchConfiguration($this->registry->watchRecord($model), $this->context);
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

        ($this->writer ?? app(TraceWriter::class))->write([
            ...$this->context->metadata(),
            'event' => 'checkpoint',
            'label' => $label,
            'source' => ($this->source_resolver ?? app(SourceResolver::class))->resolve(),
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
        $this->registry->restore($snapshot['watch'] ?? null);
        $this->context->restore($snapshot['context'] ?? null);
    }

    /**
     * Stop all model, class, and global watches in the current scope.
     */
    public function stop(): void
    {
        $this->registry->stop();
        $this->context->reset();
    }
}
