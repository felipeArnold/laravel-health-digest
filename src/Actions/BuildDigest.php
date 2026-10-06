<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Actions;

use Carbon\CarbonInterface;
use FelipeArnold\HealthDigest\Enums\DigestSection;
use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class BuildDigest
{
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const int FINGERPRINT_LENGTH = 12;

    private const float PERCENTILE = 0.95;

    private const int ORIGINS_LIMIT = 5;

    public function __construct(private readonly ReadLogEntries $readLogEntries) {}

    /** @return array{generated_at: string, since: string, slow_queries: list<array<string, mixed>>, slow_requests: list<array<string, mixed>>, errors: list<array<string, mixed>>} */
    public function handle(CarbonInterface $since, int $limit): array
    {
        $entriesBySection = $this->readLogEntries
            ->handle(HealthDigest::slowLogPath(), HealthDigest::errorLogPath(), $since)
            ->groupBy(fn (array $entry): string => $entry['section']->value);

        $sections = collect(DigestSection::cases())->mapWithKeys(fn (DigestSection $section): array => [
            $section->value => $this->rank($entriesBySection->get($section->value, collect()), $limit),
        ]);

        return [
            'generated_at' => now()->format(self::DATETIME_FORMAT),
            'since' => $since->format(self::DATETIME_FORMAT),
            ...$sections->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function rank(Collection $entries, int $limit): array
    {
        return $entries->groupBy('group_key')
            ->map(fn (Collection $occurrences, string $groupKey): array => $this->summarize($groupKey, $occurrences))
            ->sortByDesc('impact')
            ->take($limit)
            ->map(fn (array $summary): array => Arr::except($summary, 'impact'))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return array<string, mixed>
     */
    private function summarize(string $groupKey, Collection $occurrences): array
    {
        $firstOccurrence = $occurrences->first();
        $percentileMilliseconds = $this->percentile($occurrences);

        return array_filter([
            'fingerprint' => substr(sha1($firstOccurrence['section']->value.'|'.$groupKey), 0, self::FINGERPRINT_LENGTH),
            'sample' => $firstOccurrence['sample'],
            'level' => $firstOccurrence['level'] ?? null,
            'exception' => $firstOccurrence['exception'] ?? null,
            'location' => $firstOccurrence['location'] ?? null,
            'count' => $occurrences->count(),
            'p95_ms' => $percentileMilliseconds,
            'max_ms' => $occurrences->max('time_ms'),
            'tenants' => $this->distinct($occurrences, 'tenant'),
            'origins' => array_slice($this->distinct($occurrences, 'origin'), 0, self::ORIGINS_LIMIT),
            'first_seen' => $occurrences->min('datetime')->format(self::DATETIME_FORMAT),
            'last_seen' => $occurrences->max('datetime')->format(self::DATETIME_FORMAT),
            'impact' => $occurrences->count() * ($percentileMilliseconds ?? 1),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param  Collection<int, array<string, mixed>>  $occurrences */
    private function percentile(Collection $occurrences): ?int
    {
        $sortedTimes = $occurrences->pluck('time_ms')->filter()->sort()->values();

        return $sortedTimes->get((int) ceil($sortedTimes->count() * self::PERCENTILE) - 1);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return list<string>
     */
    private function distinct(Collection $occurrences, string $key): array
    {
        return $occurrences->pluck($key)->filter()->unique()->sort()->values()->all();
    }
}
