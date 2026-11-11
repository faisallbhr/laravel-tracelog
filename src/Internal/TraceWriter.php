<?php

namespace TraceLog\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Monolog\Level;
use Monolog\LogRecord;

/** Emits trace payloads using the configured output. */
final class TraceWriter
{
    private ?TraceRotatingFileHandler $handler = null;

    public function __construct(private readonly Repository $config) {}

    /** @param array<string, mixed> $payload */
    public function write(array $payload): void
    {
        $payload = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.uP'),
            ...$payload,
        ];

        $this->handler ??= $this->createHandler();
        $this->handler->handle(new LogRecord(
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'tracelog',
            Level::Debug,
            '',
            $payload
        ));
    }

    private function createHandler(): TraceRotatingFileHandler
    {
        $handler = new TraceRotatingFileHandler(
            storage_path('logs/tracelog.log'),
            (int) $this->config->get('tracelog.logging.max_size', 10 * 1024 * 1024),
            (int) $this->config->get('tracelog.logging.days', 7),
            new DateTimeZone((string) $this->config->get('app.timezone', 'UTC'))
        );
        $handler->setFormatter(new TraceJsonFormatter);

        return $handler;
    }
}
