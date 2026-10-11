<?php

declare(strict_types=1);

use FelipeArnold\HealthDigest\Mcp\HealthDigestServer;
use FelipeArnold\HealthDigest\Mcp\Tools\ListProblems;
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

    $this->slowQuery = fn (string $datetime, string $sql, int $milliseconds, string $tenant): string => json_encode([
        'message' => 'slow',
        'context' => [
            'type' => 'slow_queries',
            'sql' => $sql,
            'time_ms' => $milliseconds,
            'connection' => 'tenant',
            'origin' => 'GET orders',
            'tenant' => $tenant,
        ],
        'level' => 300,
        'level_name' => 'WARNING',
        'channel' => 'production',
        'datetime' => Carbon::parse($datetime)->format('Y-m-d\TH:i:s.uP'),
        'extra' => [],
    ]);

    $this->errorLine = fn (string $datetime, string $message, string $exceptionClass, string $file, int $line): string => "[{$datetime}] production.ERROR: {$message} "
        .'{"userId":1,"exception":"[object] ('.str_replace('\\', '\\\\', $exceptionClass)."(code: 0): {$message} at {$file}:{$line})";

    File::put($this->storageDirectory.'/logs/health-digest-2026-10-05.log', implode(PHP_EOL, [
        ($this->slowQuery)('2026-10-05 09:00:00', 'select * from orders where customer_id = 10', 600, 'ACME'),
        ($this->slowQuery)('2026-10-05 10:00:00', 'select * from orders where customer_id = 20', 900, 'ACME'),
        ($this->slowQuery)('2026-10-05 11:00:00', 'select * from invoices where id = 1', 700, 'GLOBEX'),
    ]).PHP_EOL);

    File::put($this->storageDirectory.'/logs/laravel.log', implode(PHP_EOL, [
        ($this->errorLine)('2026-10-05 10:00:00', 'Inconsistent balance for account 7', 'App\Exceptions\BalanceException', base_path('app/Actions/Foo.php'), 42),
        ($this->errorLine)('2026-10-05 11:00:00', 'Inconsistent balance for account 9', 'App\Exceptions\BalanceException', base_path('app/Actions/Foo.php'), 42),
    ]).PHP_EOL);
});

afterEach(function (): void {
    app()->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->storageDirectory);
    Carbon::setTestNow();
});

describe('list-problems tool', function (): void {
    it('lists errors and slow queries ranked and grouped', function (): void {
        $response = HealthDigestServer::tool(ListProblems::class);

        $response->assertOk()
            ->assertSee('app/Actions/Foo.php:42')
            ->assertSee('App\\\\Exceptions\\\\BalanceException')
            ->assertSee('select * from orders where customer_id = ?')
            ->assertSee('select * from invoices where id = ?');
    });

    it('returns a single section', function (): void {
        HealthDigestServer::tool(ListProblems::class, ['section' => 'errors'])
            ->assertSee('BalanceException')
            ->assertDontSee('slow_queries');
    });

    it('filters by search text', function (): void {
        HealthDigestServer::tool(ListProblems::class, ['section' => 'slow_queries', 'search' => 'invoices'])
            ->assertSee('select * from invoices where id = ?')
            ->assertDontSee('from orders');
    });

    it('filters slow queries by tenant and leaves errors alone', function (): void {
        HealthDigestServer::tool(ListProblems::class, ['tenant' => 'GLOBEX'])
            ->assertSee('select * from invoices where id = ?')
            ->assertSee('BalanceException')
            ->assertDontSee('from orders');
    });

    it('returns only the item with the given fingerprint', function (): void {
        $this->artisan('health:digest')->assertSuccessful();
        $fingerprint = json_decode(File::get($this->storageDirectory.'/app/health-digest/latest.json'), true)['slow_queries'][0]['fingerprint'];

        HealthDigestServer::tool(ListProblems::class, ['fingerprint' => $fingerprint])
            ->assertSee($fingerprint)
            ->assertDontSee('BalanceException')
            ->assertDontSee('from invoices');
    });

    it('ignores problems older than the window', function (): void {
        HealthDigestServer::tool(ListProblems::class, ['hours' => 1])
            ->assertDontSee('BalanceException')
            ->assertDontSee('from orders');
    });

    it('is registered as a read-only tool', function (): void {
        HealthDigestServer::tools()->assertRegistered(ListProblems::class);

        expect(app(ListProblems::class)->toArray()['annotations'])->toHaveKey('readOnlyHint', true);
    });
});

describe('http endpoint', function (): void {
    beforeEach(function (): void {
        $this->rpc = fn (array $headers = []) => $this->postJson(
            '/mcp/health-digest',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $headers + ['Accept' => 'application/json, text/event-stream'],
        );
    });

    it('rejects a request without token', function (): void {
        ($this->rpc)()->assertUnauthorized();
    });

    it('rejects a wrong token', function (): void {
        ($this->rpc)(['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    });

    it('serves the tool with the right token', function (): void {
        ($this->rpc)(['Authorization' => 'Bearer secret-token'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'list-problems');
    });

    it('does not answer when no token is configured', function (): void {
        config()->set('health-digest.mcp.token', null);

        ($this->rpc)(['Authorization' => 'Bearer '])->assertNotFound();
    });
});
