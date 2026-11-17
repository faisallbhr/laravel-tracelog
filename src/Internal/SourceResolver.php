<?php

namespace TraceLog\Internal;

/** Finds the first application call site without capturing arguments. */
final class SourceResolver
{
    public function resolve(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
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
