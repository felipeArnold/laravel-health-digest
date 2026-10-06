# Laravel Health Digest

Logs slow queries and slow requests as JSON and groups them, together with the
errors in your application log, into a ranked digest that a human or an AI
agent can turn into tickets.

- **Slow queries** — SQL above a threshold, without bindings, with the origin
  that ran it (route, Livewire component and method, queued job or command).
- **Slow requests** — HTTP and Livewire requests above a threshold.
- **Errors** — `ERROR` and above from `laravel.log` (single or daily), grouped
  by exception class and location.
- **Digest** — `php artisan health:digest` groups entries that differ only by
  literals, masks numbers, quoted strings and e-mails, and ranks by
  `count × p95`.

## Requirements

PHP 8.3+, Laravel 12 or 13.

## Installation

```bash
composer require felipearnold/laravel-health-digest
php artisan vendor:publish --tag=health-digest-config   # optional
```

The service provider is auto-discovered. It:

- registers a daily JSON log channel named `health-digest`
  (`storage/logs/health-digest-YYYY-MM-DD.log`) unless you define one;
- listens to `QueryExecuted`, `JobProcessing` and `CommandStarting`;
- prepends `LogSlowRequests` to the `web` middleware group.

### Identify the tenant (optional)

```php
use FelipeArnold\HealthDigest\HealthDigest;

public function boot(): void
{
    HealthDigest::resolveTenantUsing(fn () => auth()->user()?->company_id);
}
```

The value appears as `tenant` in each log entry and as `tenants` in the digest.
A resolver that throws is treated as `null`; it never breaks the request.

### Schedule the digest

```php
Schedule::command('health:digest')->twiceDailyAt(7, 13, 30)->withoutOverlapping();
```

## Configuration

| Key | Env | Default |
|---|---|---|
| `enabled` | `HEALTH_DIGEST_ENABLED` | `true` |
| `channel` | — | `health-digest` |
| `log_path` | `HEALTH_DIGEST_LOG_PATH` | `storage/logs/health-digest.log` |
| `retention_days` | `HEALTH_DIGEST_RETENTION_DAYS` | `14` |
| `query_threshold_ms` | `HEALTH_DIGEST_QUERY_MS` | `500` (0 disables) |
| `request_threshold_ms` | `HEALTH_DIGEST_REQUEST_MS` | `3000` (0 disables) |
| `middleware_groups` | — | `['web']` |
| `error_log_path` | `HEALTH_DIGEST_ERROR_LOG_PATH` | `storage/logs/laravel.log` |
| `output_path` | `HEALTH_DIGEST_OUTPUT_PATH` | `storage/app/health-digest/latest.json` |

## Digest format

```bash
php artisan health:digest --hours=12 --limit=20
```

```json
{
  "generated_at": "2026-10-05 13:30:00",
  "since": "2026-10-05 01:30:00",
  "slow_queries": [
    {
      "fingerprint": "3f9a1c0b7d2e",
      "sample": "select * from orders where customer_id = ?",
      "count": 3,
      "p95_ms": 1000,
      "max_ms": 1000,
      "tenants": ["ACME", "GLOBEX"],
      "origins": ["GET orders", "livewire orders.list@save"],
      "first_seen": "2026-10-05 09:00:00",
      "last_seen": "2026-10-05 11:00:00"
    }
  ],
  "slow_requests": [],
  "errors": [
    {
      "fingerprint": "a81c22e09f41",
      "sample": "Inconsistent balance for account ?",
      "exception": "App\\Exceptions\\BalanceException",
      "location": "app/Actions/Foo.php:42",
      "level": "ERROR",
      "count": 2,
      "first_seen": "2026-10-05 10:00:00",
      "last_seen": "2026-10-05 11:00:00"
    }
  ]
}
```

`fingerprint` is stable across runs, so it can deduplicate tickets.

## Privacy

Query bindings are never logged. Error samples drop the `(Connection: …, SQL: …)`
suffix of query exceptions and mask quoted strings, numbers, dates, documents
and e-mails. Livewire component and method names are accepted only when they
look like identifiers, so free text from the request payload never reaches the
digest.

## Testing

```bash
composer test
```

## License

MIT
