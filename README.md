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
- `only()`, `except()`, `events()`, and `sensitive()` on a watch configuration
- the `ModelEvent` enum
- the `Traceable` trait

Classes under the `TraceLog\Internal` namespace are implementation details and
are not part of the supported public API.

## Log output

TraceLog writes at the `debug` level through Laravel's default log channel, so
the destination follows your application's logging configuration.

An update produces a structured entry like this:

```text
[2026-11-10 13:57:06] local.DEBUG: {
    "event": "updated",
    "model": "App\\Models\\Order",
    "id": 123,
    "changes": {
        "status": {
            "old": "draft",
            "new": "paid"
        }
    },
    "source": "/path/to/app/Http/Controllers/OrderController.php:42"
}
```

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
