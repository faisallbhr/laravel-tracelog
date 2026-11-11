<?php

namespace TraceLog\Internal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;

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
        if ($this->max_bytes < 1) {
            throw new InvalidArgumentException('TraceLog maximum part size must be greater than zero.');
        }

        $this->date = $this->today();
        $this->part = $this->latestPart($this->date);

        parent::__construct($this->path(), Level::Debug, true, null, true);
    }

    protected function write(LogRecord $record): void
    {
        $formatted = (string) $record->formatted;

        $this->ensureDirectory();
        $lock_path = $this->base_path.'.lock';
        $lock = fopen($lock_path, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Unable to lock the TraceLog rotation file.');
        }

        try {
            $this->rotateIfNeeded(strlen($formatted));
            if (file_put_contents($this->path(), $formatted, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to write the TraceLog file.');
            }
            $this->prune();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rotateIfNeeded(int $formatted_size): void
    {
        $date = $this->today();
        $latest_part = $this->latestPart($date);

        if ($date !== $this->date || $latest_part > $this->part) {
            $this->date = $date;
            $this->part = $latest_part;
        }

        $path = $this->path();
        clearstatcache(true, $path);
        $current_size = is_file($path) ? (int) filesize($path) : 0;

        if ($current_size > 0 && $current_size + $formatted_size > $this->max_bytes) {
            $this->part++;
        }

        // An oversized event gets its own part so JSONL records remain atomic.
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->base_path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the TraceLog directory.');
        }
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
