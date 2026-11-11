<?php

namespace TraceLog\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Rotates JSONL output by day and size while retaining recent days. */
final class TraceRotatingFileHandler extends StreamHandler
{
    private string $date;

    private int $part;

    public function __construct(
        private readonly string $base_path,
        private readonly int $max_bytes,
        private readonly int $retention_days,
        private readonly DateTimeZone $timezone
    ) {
        $this->date = $this->today();
        $this->part = $this->latestPart($this->date);

        if (is_file($this->path()) && filesize($this->path()) >= $this->max_bytes) {
            $this->part++;
        }

        parent::__construct($this->path(), Level::Debug, true, null, true);
    }

    protected function write(LogRecord $record): void
    {
        $formatted = (string) $record->formatted;
        $date = $this->today();

        if ($date !== $this->date) {
            $this->date = $date;
            $this->part = $this->latestPart($date);
            $this->close();
            $this->url = $this->path();
        } elseif (is_file($this->path()) && filesize($this->path()) + strlen($formatted) > $this->max_bytes) {
            $this->part++;
            $this->close();
            $this->url = $this->path();
        }

        parent::write($record);
        $this->prune();
    }

    private function path(): string
    {
        $info = pathinfo($this->base_path);

        return $info['dirname'].DIRECTORY_SEPARATOR.$info['filename'].'-'.$this->date.'-'.$this->part.'.'.$info['extension'];
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d');
    }

    private function latestPart(string $date): int
    {
        $info = pathinfo($this->base_path);
        $pattern = $info['dirname'].DIRECTORY_SEPARATOR.$info['filename'].'-'.$date.'-*.'.$info['extension'];
        $parts = [];

        foreach (glob($pattern) ?: [] as $path) {
            if (preg_match('/-(\d+)\.log\z/D', $path, $matches) === 1) {
                $parts[] = (int) $matches[1];
            }
        }

        return $parts === [] ? 1 : max($parts);
    }

    private function prune(): void
    {
        if ($this->retention_days < 1) {
            return;
        }

        $info = pathinfo($this->base_path);
        $pattern = $info['dirname'].DIRECTORY_SEPARATOR.$info['filename'].'-*-*.'.$info['extension'];
        $cutoff = (new DateTimeImmutable('now', $this->timezone))
            ->modify('-'.($this->retention_days - 1).' days')
            ->setTime(0, 0);

        foreach (glob($pattern) ?: [] as $path) {
            if (preg_match('/-(\d{4}-\d{2}-\d{2})-\d+\.log\z/D', $path, $matches) !== 1) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1], $this->timezone);

            if ($date !== false && $date < $cutoff && is_writable($path)) {
                unlink($path);
            }
        }
    }
}
