<?php

declare(strict_types=1);

use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->logDirectory = sys_get_temp_dir().'/health-digest-dashboard-'.Str::uuid();

    File::ensureDirectoryExists($this->logDirectory);
    config()->set('logging.channels.health-digest.path', $this->logDirectory.'/health-digest.log');
    config()->set('health-digest.error_log_path', $this->logDirectory.'/laravel.log');
    Carbon::setTestNow('2026-10-05 13:30:00');

    $this->slowQuery = fn (string $datetime, string $sql, int $milliseconds, string $tenant, string $origin = 'GET orders'): string => json_encode([
        'message' => 'slow_query',
        'context' => [
            'type' => 'slow_queries',
            'sql' => $sql,
            'time_ms' => $milliseconds,
            'connection' => 'tenant',
            'origin' => $origin,
            'tenant' => $tenant,
        ],
        'level' => 300,
        'level_name' => 'WARNING',
        'channel' => 'production',
        'datetime' => Carbon::parse($datetime)->format('Y-m-d\TH:i:s.uP'),
        'extra' => [],
    ]);

    $this->writeSlowLog = function (array $lines): void {
        File::put($this->logDirectory.'/health-digest-2026-10-05.log', implode(PHP_EOL, $lines).PHP_EOL);
    };

    $this->allowDashboard = fn () => Gate::define(HealthDigest::DASHBOARD_GATE, fn ($user = null): bool => true);
});

afterEach(function (): void {
    File::deleteDirectory($this->logDirectory);
    Carbon::setTestNow();
});

it('denies the dashboard outside the local environment by default', function (): void {
    $this->get('/health-digest/slow-queries')->assertForbidden();
});

it('redirects the dashboard root to the slow queries page', function (): void {
    ($this->allowDashboard)();

    $this->get('/health-digest')->assertRedirect('/health-digest/slow-queries');
});

it('lists slow queries grouped by literals and ranked by impact', function (): void {
    ($this->allowDashboard)();
    ($this->writeSlowLog)([
        ($this->slowQuery)('2026-10-05 09:00:00', 'select * from orders where customer_id = 10', 600, 'ACME'),
        ($this->slowQuery)('2026-10-05 10:00:00', 'select * from orders where customer_id = 25', 1_000, 'GLOBEX', 'livewire orders.list@save'),
        ($this->slowQuery)('2026-10-05 11:00:00', 'select * from orders where customer_id = 99', 800, 'ACME'),
        ($this->slowQuery)('2026-10-05 12:00:00', 'select * from users where id = 1', 2_000, 'ACME'),
    ]);

    $this->get('/health-digest/slow-queries')
        ->assertSuccessful()
        ->assertSeeInOrder([
            'select * from orders where customer_id = ?',
            '3',
            '1,000 ms',
            'select * from users where id = ?',
        ])
        ->assertSee('livewire orders.list@save')
        ->assertSee('GLOBEX')
        ->assertDontSee('customer_id = 25');
});

it('limits the list to the selected period', function (): void {
    ($this->allowDashboard)();
    ($this->writeSlowLog)([
        ($this->slowQuery)('2026-10-05 08:00:00', 'select 1 from morning', 900, 'ACME'),
        ($this->slowQuery)('2026-10-05 13:00:00', 'select 1 from recent', 900, 'ACME'),
    ]);

    $this->get('/health-digest/slow-queries?hours=1')
        ->assertSee('select ? from recent')
        ->assertDontSee('select ? from morning');
});

it('falls back to the last 24 hours for an unsupported period', function (): void {
    ($this->allowDashboard)();
    ($this->writeSlowLog)([
        ($this->slowQuery)('2026-10-05 08:00:00', 'select 1 from morning', 900, 'ACME'),
    ]);

    $this->get('/health-digest/slow-queries?hours=99999')
        ->assertSuccessful()
        ->assertSee('select ? from morning');
});

it('filters by tenant before counting occurrences', function (): void {
    ($this->allowDashboard)();
    ($this->writeSlowLog)([
        ($this->slowQuery)('2026-10-05 09:00:00', 'select * from orders where id = 1', 600, 'ACME'),
        ($this->slowQuery)('2026-10-05 10:00:00', 'select * from orders where id = 2', 700, 'GLOBEX'),
        ($this->slowQuery)('2026-10-05 11:00:00', 'select * from invoices where id = 3', 800, 'GLOBEX'),
    ]);

    $this->get('/health-digest/slow-queries?tenant=ACME')
        ->assertSee('select * from orders where id = ?')
        ->assertDontSee('select * from invoices where id = ?')
        ->assertViewHas('queries', fn (array $queries): bool => $queries[0]['count'] === 1);
});

it('filters by text in the normalized query', function (): void {
    ($this->allowDashboard)();
    ($this->writeSlowLog)([
        ($this->slowQuery)('2026-10-05 09:00:00', 'select * from orders where id = 1', 600, 'ACME'),
        ($this->slowQuery)('2026-10-05 10:00:00', 'select * from invoices where id = 2', 700, 'ACME'),
    ]);

    $this->get('/health-digest/slow-queries?search=INVOICES')
        ->assertSee('select * from invoices where id = ?')
        ->assertDontSee('select * from orders where id = ?');
});

it('shows an empty state when no slow query was logged', function (): void {
    ($this->allowDashboard)();

    $this->get('/health-digest/slow-queries')
        ->assertSuccessful()
        ->assertSee('No slow queries in this period');
});

it('escapes the SQL sample', function (): void {
    ($this->allowDashboard)();
    ($this->writeSlowLog)([
        ($this->slowQuery)('2026-10-05 09:00:00', 'select `<script>alert(1)</script>` from orders', 600, 'ACME'),
    ]);

    $this->get('/health-digest/slow-queries')
        ->assertSuccessful()
        ->assertDontSee('<script>alert', false);
});
