<?php

namespace TraceLog\Internal;

use Throwable;
use WeakMap;

/** Records reported exceptions without changing Laravel's reporting flow. */
final class ExceptionRecorder
{
    /** @var WeakMap<Throwable, true> */
    private WeakMap $recorded;

    public function __construct(
        private readonly WatchRegistry $registry,
        private readonly TraceContext $context,
        private readonly TraceWriter $writer,
        private readonly bool $include_message = false,
    ) {
        $this->recorded = new WeakMap;
    }

    /**
     * Record a reported exception when an explicit watch is active.
     */
    public function record(Throwable $exception): void
    {
        if (! $this->registry->hasActiveWatch() || isset($this->recorded[$exception])) {
            return;
        }

        $this->recorded[$exception] = true;

        try {
            $this->writer->write([
                ...$this->context->metadata(),
                'event' => 'exception',
                'exception' => $exception::class,
                'message' => $this->include_message ? $exception->getMessage() : '[REDACTED]',
                'source' => $exception->getFile().':'.$exception->getLine(),
            ]);
        } catch (Throwable) {
            // Exception correlation must never mask the original exception.
        }
    }
}
