<?php

namespace TraceLog\Internal;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/** Formats only the event payload as one JSON object per line. */
final class TraceJsonFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        return (string) json_encode(
            $record->context,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
        )."\n";
    }

    /** @param list<LogRecord> $records */
    public function formatBatch(array $records): string
    {
        return implode('', array_map(fn (LogRecord $record): string => $this->format($record), $records));
    }
}
