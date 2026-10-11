<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Mcp\Tools;

use FelipeArnold\HealthDigest\Actions\BuildDigest;
use FelipeArnold\HealthDigest\Actions\ReadLogEntries;
use FelipeArnold\HealthDigest\Enums\DigestSection;
use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-problems')]
#[Description('Lists the problems reported by the application, ranked by impact (count x p95): errors from the application log, slow queries and slow requests. Items with the same fingerprint are the same problem. Slow items carry p95_ms, max_ms, tenants and origins; errors carry the exception class and file:line location.')]
#[IsReadOnly]
class ListProblems extends Tool
{
    private const int DEFAULT_HOURS = 24;

    private const int MAX_HOURS = 720;

    private const int DEFAULT_LIMIT = 20;

    private const int MAX_LIMIT = 100;

    private const string ALL_SECTIONS = 'all';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->enum([self::ALL_SECTIONS, ...array_column(DigestSection::cases(), 'value')])
                ->description('Which problems to list. Defaults to all.'),
            'hours' => $schema->integer()->min(1)->max(self::MAX_HOURS)
                ->description('Time window in hours, counting back from now. Defaults to '.self::DEFAULT_HOURS.'.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)
                ->description('Maximum items per section. Defaults to '.self::DEFAULT_LIMIT.'.'),
            'search' => $schema->string()
                ->description('Keeps only items whose SQL, message or origin contains this text, case-insensitive.'),
            'tenant' => $schema->string()
                ->description('Keeps only slow queries and slow requests that happened for this tenant. Errors carry no tenant and are not filtered.'),
            'fingerprint' => $schema->string()
                ->description('Returns only the item with this fingerprint, to look at one problem again.'),
        ];
    }

    public function handle(Request $request, ReadLogEntries $readLogEntries, BuildDigest $buildDigest): Response
    {
        $hours = min(max((int) $request->get('hours', self::DEFAULT_HOURS), 1), self::MAX_HOURS);
        $limit = min(max((int) $request->get('limit', self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $section = (string) $request->get('section', self::ALL_SECTIONS);
        $search = trim((string) $request->get('search'));
        $tenant = trim((string) $request->get('tenant'));
        $fingerprint = trim((string) $request->get('fingerprint'));

        $since = now()->subHours($hours);
        $entriesBySection = $readLogEntries
            ->handle(HealthDigest::slowLogPath(), HealthDigest::errorLogPath(), $since)
            ->groupBy(fn (array $entry): string => $entry['section']->value);

        $sections = collect(DigestSection::cases())
            ->filter(fn (DigestSection $digestSection): bool => $section === self::ALL_SECTIONS || $section === $digestSection->value)
            ->mapWithKeys(fn (DigestSection $digestSection): array => [
                $digestSection->value => $this->problems(
                    $entriesBySection->get($digestSection->value, collect()),
                    $digestSection,
                    $search,
                    $tenant,
                    $fingerprint,
                    $limit,
                    $buildDigest,
                ),
            ]);

        return Response::json([
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'since' => $since->format('Y-m-d H:i:s'),
            ...$sections->all(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function problems(Collection $entries, DigestSection $section, string $search, string $tenant, string $fingerprint, int $limit, BuildDigest $buildDigest): array
    {
        $filtered = $entries
            ->when($search !== '', fn (Collection $matching): Collection => $matching->filter(
                fn (array $entry): bool => Str::contains($entry['group_key'].' '.($entry['origin'] ?? ''), $search, ignoreCase: true),
            ))
            ->when($tenant !== '' && $section !== DigestSection::Errors, fn (Collection $matching): Collection => $matching->filter(
                fn (array $entry): bool => (string) ($entry['tenant'] ?? '') === $tenant,
            ));

        if ($fingerprint === '') {
            return $buildDigest->rank($filtered, $limit);
        }

        return array_values(array_filter(
            $buildDigest->rank($filtered, PHP_INT_MAX),
            fn (array $problem): bool => $problem['fingerprint'] === $fingerprint,
        ));
    }
}
