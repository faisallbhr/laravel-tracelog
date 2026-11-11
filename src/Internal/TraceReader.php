<?php

namespace TraceLog\Internal;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use SplFileObject;
use Throwable;
use TraceLog\Exceptions\TraceFileNotFoundException;
use TraceLog\Exceptions\TraceFileUnreadableException;

/** Reads validated TraceLog JSONL files without loading a file into memory. */
final class TraceReader
{
    private const FILENAME_PATTERN = '/\Atracelog-(\d{4}-\d{2}-\d{2})-([1-9]\d*)\.log\z/D';

    public function __construct(private readonly Repository $config) {}

    /** @return list<string> */
    public function files(): array
    {
        $directory = storage_path('logs');

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \DirectoryIterator($directory);

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isLink() || ! $file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            if ($this->isValidFilename($filename)) {
                $files[] = $filename;
            }
        }

        usort($files, fn (string $left, string $right): int => strnatcmp($right, $left));

        return $files;
    }

    /**
     * @return array{page: int, per_page: int, total: int, has_more: bool, traces: list<array<string, mixed>>}
     */
    public function traces(string $filename, int $page = 1, int $per_page = 25, ?string $tag = null): array
    {
        $this->validatePagination($page, $per_page);
        $groups = [];

        foreach ($this->records($filename) as $event) {
            $trace_id = $event['trace_id'];
            $groups[$trace_id] ??= $this->newSummary($filename, $trace_id);
            $this->addToSummary($groups[$trace_id], $event);
        }

        $summaries = array_values($groups);

        if ($tag !== null) {
            $summaries = array_values(array_filter(
                $summaries,
                fn (array $summary): bool => in_array($tag, $summary['tags'], true)
            ));
        }

        usort($summaries, function (array $left, array $right): int {
            $latest = $right['_latest_at'] <=> $left['_latest_at'];

            return $latest !== 0 ? $latest : strcmp($left['trace_id'], $right['trace_id']);
        });

        $total = count($summaries);
        $offset = ($page - 1) * $per_page;

        foreach ($summaries as &$summary) {
            unset($summary['_latest_at']);
        }
        unset($summary);

        return [
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'has_more' => $offset + $per_page < $total,
            'traces' => array_slice($summaries, $offset, $per_page),
        ];
    }

    /** @return (array<string, mixed>|null) */
    public function trace(string $filename, string $trace_id): ?array
    {
        $this->validateTraceId($trace_id);
        $summary = null;
        $events = [];

        foreach ($this->records($filename) as $event) {
            if ($event['trace_id'] !== $trace_id) {
                continue;
            }

            $summary ??= $this->newSummary($filename, $trace_id);
            $this->addToSummary($summary, $event);

            $events[] = $event;
        }

        if ($summary === null) {
            return null;
        }

        unset($summary['_latest_at']);
        $summary['events'] = $events;

        return $summary;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function records(string $filename): \Generator
    {
        $path = $this->pathFor($filename);

        $file = new SplFileObject($path, 'rb');

        while (! $file->eof()) {
            $line = $file->fgets();

            if (trim($line) === '') {
                continue;
            }

            $event = json_decode($line, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($event) || ! $this->isValidEvent($event)) {
                continue;
            }

            yield $event;
        }
    }

    private function pathFor(string $filename): string
    {
        if (! $this->isValidFilename($filename)) {
            throw new InvalidArgumentException('Invalid TraceLog filename.');
        }

        $path = storage_path('logs').DIRECTORY_SEPARATOR.$filename;

        if (! file_exists($path)) {
            throw new TraceFileNotFoundException('TraceLog file was not found.');
        }

        if (is_link($path) || ! is_file($path)) {
            throw new TraceFileUnreadableException('TraceLog file is not a regular readable file.');
        }

        if (! is_readable($path)) {
            throw new TraceFileUnreadableException('TraceLog file is not readable.');
        }

        return $path;
    }

    private function isValidFilename(string $filename): bool
    {
        if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);

        return $date !== false && $date->format('Y-m-d') === $matches[1];
    }

    /** @param array<string, mixed> $event */
    private function isValidEvent(array $event): bool
    {
        foreach (['trace_id', 'timestamp', 'tags', 'event', 'model', 'id', 'source'] as $field) {
            if (! array_key_exists($field, $event)) {
                return false;
            }
        }

        if (! is_string($event['trace_id']) || preg_match('/\A[a-f0-9]{8}\z/D', $event['trace_id']) !== 1) {
            return false;
        }

        if (! is_string($event['timestamp']) || ! $this->isValidTimestamp($event['timestamp'])) {
            return false;
        }

        if (! is_string($event['event']) || ! is_string($event['model']) || ! is_array($event['tags']) || ! array_is_list($event['tags'])) {
            return false;
        }

        foreach ($event['tags'] as $tag) {
            if (! is_string($tag)) {
                return false;
            }
        }

        if (! is_int($event['id']) && ! is_string($event['id']) && $event['id'] !== null) {
            return false;
        }

        if ($event['source'] !== null && ! is_string($event['source'])) {
            return false;
        }

        return ! array_key_exists('changes', $event) || is_array($event['changes']);
    }

    private function isValidTimestamp(string $timestamp): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(?:[+-]\d{2}:\d{2}|Z)\z/D', $timestamp) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($timestamp);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function newSummary(string $filename, string $trace_id): array
    {
        return [
            'filename' => $filename,
            'trace_id' => $trace_id,
            'started_at' => null,
            'ended_at' => null,
            'event_count' => 0,
            'tags' => [],
            'models' => [],
            '_latest_at' => null,
        ];
    }

    /** @param array<string, mixed> $summary @param array<string, mixed> $event */
    private function addToSummary(array &$summary, array $event): void
    {
        $summary['started_at'] ??= $event['timestamp'];
        $summary['ended_at'] = $event['timestamp'];
        $summary['event_count']++;
        $timestamp = new DateTimeImmutable($event['timestamp']);

        if ($summary['_latest_at'] === null || $timestamp > $summary['_latest_at']) {
            $summary['_latest_at'] = $timestamp;
        }

        foreach ($event['tags'] as $tag) {
            if (! in_array($tag, $summary['tags'], true)) {
                $summary['tags'][] = $tag;
            }
        }

        if (! in_array($event['model'], $summary['models'], true)) {
            $summary['models'][] = $event['model'];
        }
    }

    private function validateTraceId(string $trace_id): void
    {
        if (preg_match('/\A[a-f0-9]{8}\z/D', $trace_id) !== 1) {
            throw new InvalidArgumentException('Invalid TraceLog trace ID.');
        }
    }

    private function validatePagination(int $page, int $per_page): void
    {
        $max_page_size = (int) $this->config->get('tracelog.reader.max_page_size', 100);

        if ($page < 1 || $per_page < 1 || $per_page > $max_page_size) {
            throw new InvalidArgumentException('Invalid TraceLog pagination values.');
        }
    }
}
