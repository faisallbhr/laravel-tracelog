# Laravel TraceLog

[![CI](https://github.com/faisallbhr/laravel-tracelog/actions/workflows/ci.yml/badge.svg)](https://github.com/faisallbhr/laravel-tracelog/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/faisallbhr/laravel-tracelog)](https://packagist.org/packages/faisallbhr/laravel-tracelog)
[![Downloads](https://img.shields.io/packagist/dt/faisallbhr/laravel-tracelog)](https://packagist.org/packages/faisallbhr/laravel-tracelog)
[![License](https://img.shields.io/packagist/l/faisallbhr/laravel-tracelog)](https://packagist.org/packages/faisallbhr/laravel-tracelog)

Trace Eloquent model changes without writing debug logs one by one.

Debugging model changes often means logging a few attributes, repeating the
request, and starting over when one was missed. TraceLog captures the relevant
changes for `created`, `updated`, `deleted`, `restored`, and `forceDeleted`
events in one structured debug log, including the application source that
triggered the event.

## Requirements

- PHP 8.2 or later (Laravel 13 requires PHP 8.3 or later)
- Laravel 11, 12, or 13

Laravel 11 is tested for legacy compatibility but no longer receives upstream
security fixes. Prefer Laravel 12 or later for production.

## Installation

Install TraceLog as a development dependency:

```bash
composer require --dev faisallbhr/laravel-tracelog
```

Laravel discovers the service provider and facade automatically. No config
file or manual provider registration is required.

## Usage

Call `watch()` before the model operation you want to inspect.

### Watch every model

```php
use TraceLog\Facades\TraceLog;

TraceLog::watch();

$order->update([
    'status' => 'paid',
]);
```

### Watch a model class

Every instance of the given model class will be traced:

```php
use App\Models\Order;
use TraceLog\Facades\TraceLog;

TraceLog::watch(Order::class);
```

### Watch one record

Pass a persisted model to trace only that record:

```php
use App\Models\Order;
use TraceLog\Facades\TraceLog;

$order = Order::findOrFail(123);

TraceLog::watch($order);
```

When watching a specific model instance, it must already be persisted and have
a record key.

### Filter attributes

Use `only()` to allow selected attributes and `except()` to remove attributes.
When combined, `except()` takes precedence:

```php
TraceLog::watch(Order::class)
    ->only(['status', 'total', 'updated_at'])
    ->except(['updated_at']);
```

An empty `only([])` allows no attributes, while an empty `except([])` excludes
nothing. An `updated` event is not logged when filtering removes all of its
changes.

### Trace IDs and tags

Events emitted in the same application scope share a lazily generated
`trace_id`. Add trace-level tags with `tags()`; repeated calls are additive,
trim whitespace, ignore empty values, and preserve first-seen spelling and
order:

```php
TraceLog::watch()
    ->tags(['sales-order', ' inventory ', 'sales-order']);
```

Each logged event includes `trace_id` and `tags`. Calling `stop()` clears both
the watches and the active trace metadata. Models using `Traceable` create an
untagged trace lazily when they emit their first event.

### Filter events

Select events using the existing `ModelEvent` enum:

```php
use TraceLog\Enums\ModelEvent;

TraceLog::watch(Order::class)->events([
    ModelEvent::Updated,
    ModelEvent::Deleted,
]);
```

All supported events are enabled by default. Passing `events([])` disables
logging for that watch.

### Protect sensitive attributes

TraceLog always redacts `password`, `password_confirmation`, `remember_token`,
`api_token`, `access_token`, `refresh_token`, `secret`, `secret_key`, and
`private_key`. Matching is case-insensitive, including when an attribute is
explicitly selected with `only()`.

Add application-specific sensitive attributes with `sensitive()`:

```php
TraceLog::watch(User::class)
    ->sensitive(['pin', 'security_answer']);
```

Both old and new values are written as `[REDACTED]`.

### Multiple watches

When multiple watches match a model, the most specific configuration wins:
record, model class, then global. Registering the same scope again replaces its
previous filtering configuration.

### Always trace a model

Add the `Traceable` trait when a model should always be traced without calling
`watch()`:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use TraceLog\Concerns\Traceable;

class Order extends Model
{
    use Traceable;
}
```

### Stop tracing

Clear all watches registered in the current application scope:

```php
TraceLog::stop();
```

`stop()` does not disable models using the `Traceable` trait.

## Stable API

The supported public API consists of:

- `TraceLog::watch()`, `TraceLog::stop()`, and `TraceLog::isWatching()`
- `only()`, `except()`, `events()`, `sensitive()`, and `tags()` on a watch configuration
- the `ModelEvent` enum
- the `Traceable` trait

Classes under the `TraceLog\Internal` namespace are implementation details and
are not part of the supported public API.

## Log output

TraceLog writes JSON Lines files through a dedicated daily-rotated Monolog
handler. Configure retention through `config/tracelog.php` or an environment
variable:

```env
TRACELOG_LOG_DAYS=7
TRACELOG_LOG_MAX_SIZE=10485760
```

Files are written under `storage/logs/` with the daily filename pattern
`tracelog-YYYY-MM-DD-N.log`, where `N` is the size-part number for that day.
Each part is limited to 10 MiB by default and retention keeps the configured
number of recent days. An individual event larger than the limit is kept intact
in its own part, so that part is the one exception to the size limit. A small
lock file coordinates rotation between concurrent workers. Filenames use the
application's `app.timezone`; event timestamps are ISO 8601 UTC timestamps with
microseconds.

An update produces a structured entry like this:

```json
{"timestamp":"2026-11-11T14:15:30.123456+00:00","trace_id":"a4c91e7b","tags":["sales-order"],"event":"updated","model":"App\\Models\\Order","id":123,"changes":{"status":{"old":"draft","new":"paid"}},"source":"/path/to/app/Http/Controllers/OrderController.php:42"}
```

Each physical line contains exactly one JSON object and does not include a text
log prefix. The future reader should enumerate only validated
`tracelog-YYYY-MM-DD-N.log` filenames under `storage/logs/`; a trace can span
daily files or size parts and an eight-character trace ID is not globally
unique.

### Internal trace reader

The package registers an internal, read-only `TraceLog\Internal\TraceReader`
for future UI integrations. It can list valid daily files, paginate trace
summaries for one file, and retrieve a trace by the pair `(filename, trace_id)`.
It streams files, skips malformed JSONL records, rejects path traversal and
symlinks, and enforces a pagination limit from `config/tracelog.php`.
`tracesForDate($date)` groups each trace once across all size parts for that
date; `traces($filename)` remains a per-file listing. Trace lookup reads all
size parts for the requested date. A trace crossing midnight remains split
between dates. It does not query Eloquent models or expose routes.

Laravel emits companion events for soft-delete operations. By default,
`restore()` logs both `updated` and `restored`, while `forceDelete()` logs both
`deleted` and `forceDeleted`. Use `events()` when only the specific lifecycle
event is needed.

## Important

Keep TraceLog as a development dependency. Default redaction protects common
credential names, but application-specific sensitive attributes must be added
with `sensitive()`.

Mass updates performed through an Eloquent query do not dispatch model events
and therefore are not traced:

```php
Order::query()->update(['status' => 'paid']);
```

## License

Laravel TraceLog is open-source software licensed under the [MIT license](LICENSE).
