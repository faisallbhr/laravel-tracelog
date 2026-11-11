<?php

/**
 * TraceLog logging configuration.
 *
 * @return array{logging: array{days: int}}
 */
return [
    'logging' => [
        /**
         * Number of daily JSONL files to keep. Set to 0 to keep files forever.
         * Files are written as storage/logs/tracelog-YYYY-MM-DD.log using app.timezone.
         */
        'days' => (int) env('TRACELOG_LOG_DAYS', 7),
    ],
];
