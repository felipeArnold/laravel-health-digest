<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Listeners;

use FelipeArnold\HealthDigest\Enums\DigestSection;
use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class LogSlowQuery
{
    private const string CONSOLE_ORIGIN = 'console';

    private const string UNKNOWN_COMMAND = 'unknown';

    public function handle(QueryExecuted $query): void
    {
        $thresholdInMilliseconds = (int) config('health-digest.query_threshold_ms');

        if (! config('health-digest.enabled') || $thresholdInMilliseconds <= 0 || $query->time < $thresholdInMilliseconds) {
            return;
        }

        Log::channel(HealthDigest::channel())->warning('slow_query', [
            'type' => DigestSection::SlowQueries->value,
            'sql' => $query->sql,
            'time_ms' => (int) round($query->time),
            'connection' => $query->connectionName,
            'origin' => Context::getHidden(HealthDigest::ORIGIN_CONTEXT_KEY, self::CONSOLE_ORIGIN),
            'tenant' => HealthDigest::currentTenant(),
        ]);
    }

    public function attributeToJob(JobProcessing $event): void
    {
        Context::addHidden(HealthDigest::ORIGIN_CONTEXT_KEY, 'job '.$event->job->resolveName());
    }

    public function attributeToCommand(CommandStarting $event): void
    {
        Context::addHidden(HealthDigest::ORIGIN_CONTEXT_KEY, 'command '.($event->command ?? self::UNKNOWN_COMMAND));
    }
}
