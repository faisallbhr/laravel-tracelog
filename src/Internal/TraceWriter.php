<?php

namespace TraceLog\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Emits trace payloads using the configured output. */
final class TraceWriter
{
    private ?RotatingFileHandler $handler = null;

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

    private function createHandler(): RotatingFileHandler
    {
        $handler = new RotatingFileHandler(
            storage_path('logs/tracelog.log'),
            (int) $this->config->get('tracelog.logging.days', 7),
            Level::Debug,
            true,
            null,
            false,
            RotatingFileHandler::FILE_PER_DAY,
            '{filename}-{date}',
            new DateTimeZone((string) $this->config->get('app.timezone', 'UTC'))
        );
        $handler->setFormatter(new TraceJsonFormatter);

        return $handler;
    }
}
