<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Http\Controllers;

use FelipeArnold\HealthDigest\Actions\BuildDigest;
use FelipeArnold\HealthDigest\Actions\ReadLogEntries;
use FelipeArnold\HealthDigest\Enums\DigestSection;
use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SlowQueriesController
{
    private const array HOUR_OPTIONS = [1, 6, 12, 24, 72, 168];

    private const int DEFAULT_HOURS = 24;

    private const int LIMIT = 100;

    public function __invoke(Request $request, ReadLogEntries $readLogEntries, BuildDigest $buildDigest): View
    {
        Gate::authorize(HealthDigest::DASHBOARD_GATE);

        $hours = in_array($request->integer('hours'), self::HOUR_OPTIONS, true) ? $request->integer('hours') : self::DEFAULT_HOURS;
        $tenant = $request->string('tenant')->trim()->toString();
        $search = $request->string('search')->trim()->toString();

        $entries = $readLogEntries->handle(HealthDigest::slowLogPath(), null, now()->subHours($hours))
            ->filter(fn (array $entry): bool => $entry['section'] === DigestSection::SlowQueries);

        $queries = $buildDigest->rank(
            $entries
                ->when($tenant !== '', fn (Collection $filtered): Collection => $filtered->filter(fn (array $entry): bool => (string) $entry['tenant'] === $tenant))
                ->when($search !== '', fn (Collection $filtered): Collection => $filtered->filter(fn (array $entry): bool => Str::contains($entry['group_key'], $search, ignoreCase: true))),
            self::LIMIT,
        );

        return view('health-digest::slow-queries', [
            'queries' => $queries,
            'tenants' => $entries->pluck('tenant')->filter()->unique()->sort()->values()->all(),
            'hourOptions' => self::HOUR_OPTIONS,
            'hours' => $hours,
            'tenant' => $tenant,
            'search' => $search,
            'limit' => self::LIMIT,
            'occurrences' => array_sum(array_column($queries, 'count')),
            'slowestMilliseconds' => max([0, ...array_column($queries, 'max_ms')]),
        ]);
    }
}
