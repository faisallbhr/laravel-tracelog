<?php

/**
 * TraceLog logging configuration.
 *
 * @return array{logging: array{days: int, max_size: int}, reader: array{max_page_size: int}}
 */
return [
    'logging' => [
        /**
         * Number of daily JSONL files to keep. Set to 0 to keep files forever.
         * Files are written as storage/logs/tracelog-YYYY-MM-DD-N.log using app.timezone.
         */
        'days' => (int) env('TRACELOG_LOG_DAYS', 7),

        /** Maximum size of each JSONL part in bytes. Defaults to 10 MiB. */
        'max_size' => (int) env('TRACELOG_LOG_MAX_SIZE', 10 * 1024 * 1024),
    ],

    'reader' => [
        /** Maximum perPage value accepted by traces(). */
        'max_page_size' => (int) env('TRACELOG_READER_MAX_PAGE_SIZE', 100),
    ],
];
