<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TraceLog Viewer</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23176b59'/%3E%3Cpath d='M8 16h6m4 0h6M14 16l3-6m0 6-3 6' fill='none' stroke='%23f4f5f0' stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5'/%3E%3Ccircle cx='8' cy='16' r='3' fill='%23f4f5f0'/%3E%3Ccircle cx='24' cy='16' r='3' fill='%23f4f5f0'/%3E%3Ccircle cx='17' cy='10' r='3' fill='%23f4f5f0'/%3E%3Ccircle cx='17' cy='22' r='3' fill='%23f4f5f0'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="{{ route('tracelog.viewer.css') }}">

</head>
<body>
<main id="tracelog-viewer"
      data-dates-url="{{ route('tracelog.api.dates') }}"
      data-traces-url="{{ route('tracelog.api.traces') }}"
      data-trace-url-template="{{ route('tracelog.api.trace', ['trace_id' => '__TRACE_ID__']) }}"
      data-page-size="{{ config('tracelog.reader.page_size', 25) }}">
    <div class="viewer-layout">
        <aside class="trace-sidebar" aria-label="Log dates">
            <h1>TraceLog</h1>
            <div class="sidebar-heading">
                <h2>Log dates</h2>
                <span id="date-count"></span>
            </div>
            <div id="trace-date" class="date-list" role="listbox" aria-label="Log dates"></div>
        </aside>

        <section class="trace-content">
            <section class="filters" aria-label="Trace filters">
                <label for="trace-tag">Tag <input id="trace-tag" type="text" autocomplete="off" placeholder="Filter by tag, e.g. checkout"></label>
                <div class="filter-actions">
                    <button class="button refresh-button" id="trace-refresh" type="button" title="Refresh traces" aria-label="Refresh traces">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M20 11a8 8 0 0 0-14.8-4L3 10m0 0h4M3 10V6M4 13a8 8 0 0 0 14.8 4L21 14m0 0h-4m4 0v4"/>
                        </svg>
                    </button>
                    <details class="settings">
                    <summary aria-label="Open display settings" title="Display settings">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 6h6m4 0h6M4 12h2m4 0h10M4 18h10m4 0h2"/>
                            <circle cx="12" cy="6" r="2"/>
                            <circle cx="7" cy="12" r="2"/>
                            <circle cx="15" cy="18" r="2"/>
                        </svg>
                        <span class="sr-only">Display settings</span>
                    </summary>
                    <div class="settings-menu">
                        <fieldset>
                            <legend>Theme</legend>
                            <label class="theme-option"><input type="radio" name="theme" value="system"> System</label>
                            <label class="theme-option"><input type="radio" name="theme" value="light"> Light</label>
                            <label class="theme-option"><input type="radio" name="theme" value="dark"> Dark</label>
                        </fieldset>
                    </div>
                    </details>
                </div>
            </section>

            <section id="trace-list" class="trace-list" aria-label="Trace results" aria-live="polite"></section>
            <nav id="trace-pagination" class="pagination" aria-label="Trace pages"></nav>
        </section>
    </div>
</main>
<script src="{{ route('tracelog.viewer.js') }}"></script>
