# Laravel TraceLog

Trace Eloquent model changes without adding `Log::debug()` calls one by one.

Debugging model changes often means logging a few attributes, repeating the
request, and starting over when one was missed. TraceLog captures the relevant
changes for `created`, `updated`, `deleted`, `restored`, and `forceDeleted`
events in one structured debug log, including the application source that
triggered the event.

## Requirements

- PHP 8.5 or later
- Laravel 13

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

An unsaved model cannot be watched because it does not have a record key yet.

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

## Important

TraceLog records model attribute values and currently provides no redaction or
attribute exclusion. Keep it as a development dependency and avoid tracing
sensitive data in shared or production logs.

## License

Laravel TraceLog is open-source software licensed under the [MIT license](LICENSE).
