<?php

declare(strict_types=1);

use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    $this->slowLogDirectory = sys_get_temp_dir().'/slow-query-'.Str::uuid();

    config()->set('logging.channels.health-digest.path', $this->slowLogDirectory.'/health-digest.log');
    config()->set('health-digest.query_threshold_ms', 500);

    $this->dispatchQueryTaking = function (float $milliseconds): void {
        event(new QueryExecuted(
            'select * from customers where document = ?',
            ['123.456.789-00'],
            $milliseconds,
            Mockery::mock(Connection::class, ['getName' => 'tenant', 'prepareBindings' => []]),
        ));
    };

    $this->slowLogEntries = fn (): array => collect(File::glob($this->slowLogDirectory.'/health-digest-*.log'))
        ->flatMap(fn (string $logFile): array => file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line): array => json_decode($line, true))
        ->all();
});

afterEach(function (): void {
    File::deleteDirectory($this->slowLogDirectory);
});

it('logs a query above the threshold with origin and tenant but without bindings', function (): void {
    HealthDigest::resolveTenantUsing(fn (): string => 'ACME');
    Context::addHidden(HealthDigest::ORIGIN_CONTEXT_KEY, 'livewire orders.list@save');

    ($this->dispatchQueryTaking)(750.4);

    $entries = ($this->slowLogEntries)();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['message'])->toBe('slow_query')
        ->and($entries[0]['context'])->toMatchArray([
            'type' => 'slow_queries',
            'sql' => 'select * from customers where document = ?',
            'time_ms' => 750,
            'connection' => 'tenant',
            'origin' => 'livewire orders.list@save',
            'tenant' => 'ACME',
        ])
        ->and(json_encode($entries[0]))->not->toContain('123.456.789-00');
});

it('logs a query exactly at the threshold', function (): void {
    ($this->dispatchQueryTaking)(500.0);

    expect(($this->slowLogEntries)())->toHaveCount(1);
});

it('uses console as origin and no tenant when nothing identifies them', function (): void {
    ($this->dispatchQueryTaking)(900);

    expect(($this->slowLogEntries)()[0]['context'])
        ->origin->toBe('console')
        ->tenant->toBeNull();
});

it('keeps logging with a null tenant when the tenant resolver throws', function (): void {
    HealthDigest::resolveTenantUsing(fn () => throw new RuntimeException('no session'));

    ($this->dispatchQueryTaking)(900);

    expect(($this->slowLogEntries)()[0]['context']['tenant'])->toBeNull();
});

it('attributes queries in a queued job to the job instead of the dispatching screen', function (): void {
    Context::addHidden(HealthDigest::ORIGIN_CONTEXT_KEY, 'livewire orders.list@save');
    $payloadFromDispatchingRequest = ['illuminate:log:context' => Context::dehydrate()];
    Context::flush();

    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->allows(['resolveName' => 'App\Jobs\RecalculateJob', 'payload' => $payloadFromDispatchingRequest]);

    event(new JobProcessing('redis', $job));
    ($this->dispatchQueryTaking)(900);

    expect(($this->slowLogEntries)()[0]['context']['origin'])->toBe('job App\Jobs\RecalculateJob');
});

it('attributes queries in an artisan command to the command', function (): void {
    event(new CommandStarting('orders:recalculate', new ArrayInput([]), new NullOutput));
    ($this->dispatchQueryTaking)(900);

    expect(($this->slowLogEntries)()[0]['context']['origin'])->toBe('command orders:recalculate');
});

it('ignores a query below the threshold', function (): void {
    ($this->dispatchQueryTaking)(499.9);

    expect(($this->slowLogEntries)())->toBeEmpty();
});

it('logs nothing when the threshold is zero or the package is disabled', function (string $key, mixed $value): void {
    config()->set($key, $value);

    ($this->dispatchQueryTaking)(5_000);

    expect(($this->slowLogEntries)())->toBeEmpty();
})->with([
    'threshold zero' => ['health-digest.query_threshold_ms', 0],
    'disabled' => ['health-digest.enabled', false],
]);
