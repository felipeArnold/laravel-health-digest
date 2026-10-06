<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->originalStoragePath = storage_path();
    $this->storageDirectory = sys_get_temp_dir().'/health-digest-'.Str::uuid();

    File::ensureDirectoryExists($this->storageDirectory.'/logs');
    app()->useStoragePath($this->storageDirectory);
    config()->set('logging.channels.health-digest.path', $this->storageDirectory.'/logs/health-digest.log');
    Carbon::setTestNow('2026-10-05 13:30:00');

    $this->slowEntry = fn (string $datetime, array $context): string => json_encode([
        'message' => 'slow',
        'context' => $context,
        'level' => 300,
        'level_name' => 'WARNING',
        'channel' => 'production',
        'datetime' => Carbon::parse($datetime)->format('Y-m-d\TH:i:s.uP'),
        'extra' => [],
    ]);

    $this->slowQuery = fn (string $datetime, string $sql, int $milliseconds, string $tenant, string $origin = 'GET orders'): string => ($this->slowEntry)($datetime, [
        'type' => 'slow_queries',
        'sql' => $sql,
        'time_ms' => $milliseconds,
        'connection' => 'tenant',
        'origin' => $origin,
        'tenant' => $tenant,
    ]);

    $this->exceptionContext = fn (string $exceptionClass, string $file, int $line): string => '{"userId":1,"exception":"[object] ('
        .str_replace('\\', '\\\\', $exceptionClass)."(code: 0): message at {$file}:{$line})";

    $this->writeLog = function (string $fileName, array $lines): void {
        File::put($this->storageDirectory.'/logs/'.$fileName, implode(PHP_EOL, $lines).PHP_EOL);
    };

    $this->digest = function (array $options = []): array {
        $this->artisan('health:digest', $options)->assertSuccessful();

        return json_decode(File::get($this->storageDirectory.'/app/health-digest/latest.json'), true);
    };
});

afterEach(function (): void {
    app()->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->storageDirectory);
    Carbon::setTestNow();
});

describe('digest groups and ranks the last 12 hours', function (): void {
    it('groups slow queries that differ only by literals', function (): void {
        ($this->writeLog)('health-digest-2026-10-05.log', [
            ($this->slowQuery)('2026-10-05 09:00:00', 'select * from orders where customer_id = 10', 600, 'ACME'),
            ($this->slowQuery)('2026-10-05 10:00:00', 'select  * from orders where customer_id = 25', 1_000, 'GLOBEX', 'livewire orders.list@save'),
            ($this->slowQuery)('2026-10-05 11:00:00', 'select * from orders where customer_id = 99', 800, 'ACME'),
            ($this->slowQuery)('2026-10-05 12:00:00', 'select * from users where id in (1, 2, 3)', 2_000, 'ACME'),
        ]);

        $slowQueries = ($this->digest)()['slow_queries'];

        expect($slowQueries)->toHaveCount(2)
            ->and($slowQueries[0])->toMatchArray([
                'sample' => 'select * from orders where customer_id = ?',
                'count' => 3,
                'tenants' => ['ACME', 'GLOBEX'],
                'origins' => ['GET orders', 'livewire orders.list@save'],
                'first_seen' => '2026-10-05 09:00:00',
                'last_seen' => '2026-10-05 11:00:00',
            ])
            ->and($slowQueries[0]['fingerprint'])->toHaveLength(12)
            ->and($slowQueries[1]['sample'])->toBe('select * from users where id in (?)');
    });

    it('reports the 95th percentile apart from the maximum', function (): void {
        $lines = array_map(
            fn (int $milliseconds): string => ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from a', $milliseconds, 'ACME'),
            [...range(100, 1_900, 100), 10_000],
        );
        ($this->writeLog)('health-digest-2026-10-05.log', $lines);

        expect(($this->digest)()['slow_queries'][0])->toMatchArray(['count' => 20, 'p95_ms' => 1_900, 'max_ms' => 10_000]);
    });

    it('ranks a frequent moderate query above a rare slow one', function (): void {
        ($this->writeLog)('health-digest-2026-10-05.log', [
            ...array_fill(0, 10, ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from frequent', 600, 'ACME')),
            ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from rare', 5_000, 'ACME'),
        ]);

        expect(array_column(($this->digest)()['slow_queries'], 'sample'))->toBe(['select ? from frequent', 'select ? from rare']);
    });

    it('honours the limit', function (): void {
        ($this->writeLog)('health-digest-2026-10-05.log', [
            ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from a', 600, 'ACME'),
            ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from b', 5_000, 'ACME'),
            ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from c', 700, 'ACME'),
        ]);

        expect(array_column(($this->digest)(['--limit' => 2])['slow_queries'], 'sample'))->toBe(['select ? from b', 'select ? from c']);
    });

    it('keeps long queries with the same prefix apart and truncates the sample', function (): void {
        $columns = 'select '.str_repeat('amount, ', 40);

        ($this->writeLog)('health-digest-2026-10-05.log', [
            ($this->slowQuery)('2026-10-05 09:00:00', $columns.'id from orders', 900, 'ACME'),
            ($this->slowQuery)('2026-10-05 09:00:00', $columns.'id from invoices', 900, 'ACME'),
        ]);

        $slowQueries = ($this->digest)()['slow_queries'];

        expect($slowQueries)->toHaveCount(2)
            ->and(mb_strlen($slowQueries[0]['sample']))->toBeLessThanOrEqual(303);
    });

    it('groups slow requests by origin', function (): void {
        ($this->writeLog)('health-digest-2026-10-05.log', [
            ($this->slowEntry)('2026-10-05 09:00:00', ['type' => 'slow_requests', 'origin' => 'livewire orders.list@save', 'status' => 200, 'time_ms' => 4_000, 'tenant' => 'ACME']),
            ($this->slowEntry)('2026-10-05 09:30:00', ['type' => 'slow_requests', 'origin' => 'livewire orders.list@save', 'status' => 500, 'time_ms' => 6_000, 'tenant' => 'GLOBEX']),
        ]);

        $slowRequests = ($this->digest)()['slow_requests'];

        expect($slowRequests)->toHaveCount(1)
            ->and($slowRequests[0])->toMatchArray([
                'sample' => 'livewire orders.list@save',
                'count' => 2,
                'max_ms' => 6_000,
                'tenants' => ['ACME', 'GLOBEX'],
            ]);
    });

    it('groups application errors by exception class, file and line', function (): void {
        $appException = ($this->exceptionContext)('App\Exceptions\BalanceException', base_path('app/Actions/Foo.php'), 42);

        ($this->writeLog)('laravel-2026-10-05.log', [
            "[2026-10-05 10:00:00] production.ERROR: Inconsistent balance for account 10 {$appException}",
            '[stacktrace]',
            '#0 /var/www/app/Actions/Foo.php(42): foo()',
            '"} ',
            "[2026-10-05 11:00:00] production.ERROR: Inconsistent balance for account 25 {$appException}",
            '[2026-10-05 11:30:00] production.INFO: login ok',
            '[2026-10-05 12:00:00] production.CRITICAL: Queue stalled for 30 minutes',
        ]);

        $errors = ($this->digest)()['errors'];

        expect($errors)->toHaveCount(2)
            ->and($errors[0])->toMatchArray([
                'sample' => 'Inconsistent balance for account ?',
                'exception' => 'App\Exceptions\BalanceException',
                'location' => 'app/Actions/Foo.php:42',
                'count' => 2,
            ])
            ->and($errors[1])->toMatchArray([
                'sample' => 'Queue stalled for ? minutes',
                'level' => 'CRITICAL',
                'count' => 1,
            ]);
    });

    it('splits framework errors thrown inside vendor by message', function (): void {
        $queryException = ($this->exceptionContext)('Illuminate\Database\QueryException', base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'), 838);

        ($this->writeLog)('laravel-2026-10-05.log', [
            "[2026-10-05 10:00:00] production.ERROR: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'total' (Connection: tenant, SQL: select total from x) {$queryException}",
            "[2026-10-05 10:30:00] production.ERROR: SQLSTATE[42S02]: Base table or view not found: 1146 Table 'y' doesn't exist (Connection: tenant, SQL: select * from y) {$queryException}",
        ]);

        $errors = ($this->digest)()['errors'];

        expect(array_column($errors, 'sample'))->toEqualCanonicalizing([
            'SQLSTATE[42S22]: Column not found: ? Unknown column ?',
            'SQLSTATE[42S02]: Base table or view not found: ? Table ? doesn\'t exist',
        ])->and(array_column($errors, 'location'))->each->toBe('vendor/laravel/framework/src/Illuminate/Database/Connection.php:838');
    });

    it('writes an empty digest when there are no logs', function (): void {
        expect(($this->digest)())->toMatchArray([
            'generated_at' => '2026-10-05 13:30:00',
            'since' => '2026-10-05 01:30:00',
            'slow_queries' => [],
            'slow_requests' => [],
            'errors' => [],
        ]);
    });
});

describe('digest drops stale entries and masks literals', function (): void {
    it('ignores entries older than the window and reads the previous day file', function (): void {
        ($this->writeLog)('health-digest-2026-10-04.log', [
            ($this->slowQuery)('2026-10-04 08:00:00', 'select 1 from stale', 900, 'ACME'),
            ($this->slowQuery)('2026-10-04 20:00:00', 'select 1 from yesterday_night', 900, 'ACME'),
        ]);
        ($this->writeLog)('health-digest-2026-10-05.log', [
            ($this->slowQuery)('2026-10-05 02:00:00', 'select 1 from today', 900, 'ACME'),
        ]);
        ($this->writeLog)('laravel-2026-10-04.log', [
            '[2026-10-04 08:00:00] production.ERROR: stale error',
        ]);

        $digest = ($this->digest)(['--hours' => 24]);

        expect(array_column($digest['slow_queries'], 'sample'))->toEqualCanonicalizing(['select ? from yesterday_night', 'select ? from today'])
            ->and($digest['errors'])->toBeEmpty();
    });

    it('includes an entry exactly at the window start and converts other timezones', function (): void {
        ($this->writeLog)('health-digest-2026-10-05.log', [
            ($this->slowQuery)('2026-10-05 01:30:00', 'select 1 from a', 900, 'ACME'),
            str_replace(
                Carbon::parse('2026-10-05 09:00:00')->format('Y-m-d\TH:i:s.uP'),
                '2026-10-05T12:00:00.000000+00:00',
                ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from a', 900, 'ACME'),
            ),
        ]);

        expect(($this->digest)()['slow_queries'][0])->toMatchArray([
            'count' => 2,
            'first_seen' => '2026-10-05 01:30:00',
            'last_seen' => '2026-10-05 09:00:00',
        ]);
    });

    it('drops the SQL and its inlined bindings from query exceptions', function (): void {
        $queryException = ($this->exceptionContext)('Illuminate\Database\QueryException', base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'), 838);

        ($this->writeLog)('laravel-2026-10-05.log', [
            "[2026-10-05 10:00:00] production.ERROR: SQLSTATE[23000]: Integrity constraint violation (Connection: tenant, Host: 10.0.0.1, SQL: insert into customers (document, name) values (123.456.789-00, John Doe)) {$queryException}",
        ]);

        $errors = ($this->digest)()['errors'];

        expect($errors[0]['sample'])->toBe('SQLSTATE[23000]: Integrity constraint violation')
            ->and(json_encode($errors, JSON_UNESCAPED_UNICODE))->not->toContain('123.456.789-00')->not->toContain('John');
    });

    it('masks unquoted documents, e-mails and phones in error messages', function (): void {
        ($this->writeLog)('laravel-2026-10-05.log', [
            '[2026-10-05 10:00:00] production.ERROR: Failed to send invoice to john@example.com CPF 123.456.789-00 CNPJ 12.345.678/0001-90 phone (11) 98888-7777 amount 1.250,30',
        ]);

        expect(($this->digest)()['errors'][0]['sample'])
            ->toBe('Failed to send invoice to ? CPF ? CNPJ ? phone (?) ? amount ?');
    });

    it('reads a single-file error log as well as daily files', function (): void {
        ($this->writeLog)('laravel.log', [
            '[2026-10-04 08:00:00] production.ERROR: stale error',
            '[2026-10-05 10:00:00] production.ERROR: Queue stalled for 30 minutes',
        ]);
        ($this->writeLog)('laravel-2026-10-05.log', [
            '[2026-10-05 11:00:00] production.ERROR: Queue stalled for 45 minutes',
        ]);

        expect(($this->digest)()['errors'])->toHaveCount(1)
            ->and(($this->digest)()['errors'][0])->toMatchArray(['sample' => 'Queue stalled for ? minutes', 'count' => 2]);
    });

    it('writes to the configured output path', function (): void {
        config()->set('health-digest.output_path', $this->storageDirectory.'/custom/digest.json');

        $this->artisan('health:digest')->assertSuccessful();

        expect(File::exists($this->storageDirectory.'/custom/digest.json'))->toBeTrue();
    });

    it('skips malformed slow log lines', function (): void {
        ($this->writeLog)('health-digest-2026-10-05.log', [
            'not json',
            ($this->slowEntry)('2026-10-05 09:00:00', ['type' => 'errors', 'origin' => 'x']),
            ($this->slowQuery)('2026-10-05 09:00:00', 'select 1 from a', 600, 'ACME'),
        ]);

        $digest = ($this->digest)();

        expect($digest['slow_queries'])->toHaveCount(1)
            ->and($digest['errors'])->toBeEmpty();
    });
});
