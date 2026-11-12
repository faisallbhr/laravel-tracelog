<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sensitive Attributes
    |--------------------------------------------------------------------------
    |
    | Attribute names whose values are replaced with [REDACTED] in trace
    | changes. Matching is case-insensitive.
    |
    */
    'sensitive_attributes' => [
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'secret_key',
        'private_key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Trace events are stored as JSONL files and split into daily parts.
    |
    */
    'logging' => [
        /*
        | Number of days to keep completed log parts.
        | Set to 0 to disable automatic cleanup.
        */
        'days' => (int) env('TRACELOG_LOG_DAYS', 7),

        /* Maximum size of each log part in bytes. */
        'max_size' => (int) env('TRACELOG_LOG_MAX_SIZE', 10 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reader
    |--------------------------------------------------------------------------
    |
    | Limits applied when trace data is read from the log files.
    |
    */
    'reader' => [
        /* Maximum number of traces returned per page. */
        'max_page_size' => (int) env('TRACELOG_READER_MAX_PAGE_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Viewer
    |--------------------------------------------------------------------------
    |
    | Settings for the optional web viewer and its JSON endpoints.
    |
    */
    'viewer' => [
        /* Enable the viewer. It is disabled by default. */
        'enabled' => (bool) env('TRACELOG_VIEWER_ENABLED', false),

        /* URL prefix used by the viewer routes. */
        'path' => (string) env('TRACELOG_VIEWER_PATH', 'tracelog'),

        /* Middleware applied to the viewer page and JSON endpoints. */
        'middleware' => ['web', 'auth'],
    ],
];
