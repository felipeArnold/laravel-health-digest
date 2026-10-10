<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Actions;

use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use FelipeArnold\HealthDigest\Enums\DigestSection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

class ReadLogEntries
{
    private const array ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    private const array SLOW_SECTIONS = [DigestSection::SlowQueries, DigestSection::SlowRequests];

    private const string ERROR_HEADER_PATTERN = '/^\[(?<datetime>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] [\w-]+\.(?<level>[A-Z]+): (?<message>.*)$/';

    private const string EXCEPTION_PATTERN = '/"exception":"\[object\] \((?<class>[^(]+)\(code: [^)]*\): .* at (?<file>\S+):(?<line>\d+)\)$/';

    private const string CONTEXT_SEPARATOR = ' {"';

    private const string QUERY_DETAILS_SEPARATOR = ' (Connection:';

    private const string VENDOR_DIRECTORY = 'vendor/';

    private const int SAMPLE_MAX_LENGTH = 300;

    /** @return Collection<int, array<string, mixed>> */
    public function handle(string $slowLogPath, ?string $errorLogPath, CarbonInterface $since): Collection
    {
        $errorLines = $errorLogPath === null ? LazyCollection::empty() : $this->linesFrom($errorLogPath, $since);

        return $this->linesFrom($slowLogPath, $since)
            ->map(fn (string $line): ?array => $this->parseSlowLine($line))
            ->concat($errorLines->map(fn (string $line): ?array => $this->parseErrorLine($line)))
            ->filter(fn (?array $entry): bool => $entry !== null && $entry['datetime']->greaterThanOrEqualTo($since))
            ->values()
            ->collect();
    }

    /** @return LazyCollection<int, string> */
    private function linesFrom(string $logPath, CarbonInterface $since): LazyCollection
    {
        return LazyCollection::make($this->logFilesFor($logPath, $since))
            ->filter(fn (string $logFile): bool => File::exists($logFile))
            ->flatMap(fn (string $logFile): LazyCollection => File::lines($logFile)->values())
            ->map(fn (string $line): string => rtrim($line));
    }

    /** @return list<string> */
    private function logFilesFor(string $logPath, CarbonInterface $since): array
    {
        $extension = pathinfo($logPath, PATHINFO_EXTENSION);
        $pathWithoutExtension = Str::beforeLast($logPath, '.'.$extension);

        $dailyFiles = collect(CarbonPeriod::create($since->toDateString(), now()->toDateString()))
            ->map(fn (CarbonInterface $day): string => "{$pathWithoutExtension}-{$day->toDateString()}.{$extension}");

        return [$logPath, ...$dailyFiles->all()];
    }

    /** @return array<string, mixed>|null */
    private function parseSlowLine(string $line): ?array
    {
        $record = json_decode($line, true);
        $section = DigestSection::tryFrom((string) data_get($record, 'context.type'));

        if (! in_array($section, self::SLOW_SECTIONS, true) || ! isset($record['datetime'])) {
            return null;
        }

        $groupKey = $this->slowGroupKey($section, $record);

        return [
            'section' => $section,
            'group_key' => $groupKey,
            'sample' => Str::limit($groupKey, self::SAMPLE_MAX_LENGTH),
            'datetime' => Carbon::parse($record['datetime'])->setTimezone(config('app.timezone')),
            'time_ms' => (int) data_get($record, 'context.time_ms'),
            'tenant' => data_get($record, 'context.tenant'),
            'origin' => data_get($record, 'context.origin'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseErrorLine(string $line): ?array
    {
        if (! preg_match(self::ERROR_HEADER_PATTERN, $line, $header) || ! in_array($header['level'], self::ERROR_LEVELS, true)) {
            return null;
        }

        preg_match(self::EXCEPTION_PATTERN, $line, $exception);

        $message = $this->normalize(Str::before(Str::before($header['message'], self::CONTEXT_SEPARATOR), self::QUERY_DETAILS_SEPARATOR));
        [$exceptionClass, $location] = $this->exceptionOrigin($exception);

        return [
            'section' => DigestSection::Errors,
            'group_key' => $this->errorGroupKey($header['level'], $message, $exceptionClass, $location),
            'sample' => Str::limit($message, self::SAMPLE_MAX_LENGTH),
            'datetime' => Carbon::parse($header['datetime']),
            'level' => $header['level'],
            'exception' => $exceptionClass,
            'location' => $location,
        ];
    }

    /** @param  array<string, mixed>  $record */
    private function slowGroupKey(DigestSection $section, array $record): string
    {
        return $section === DigestSection::SlowQueries
            ? $this->normalize((string) data_get($record, 'context.sql'))
            : (string) data_get($record, 'context.origin');
    }

    /**
     * @param  array<string, string>  $exception
     * @return array{0: ?string, 1: ?string}
     */
    private function exceptionOrigin(array $exception): array
    {
        if (! isset($exception['class'], $exception['file'])) {
            return [null, null];
        }

        return [stripslashes($exception['class']), Str::after($exception['file'], base_path().'/').':'.$exception['line']];
    }

    private function errorGroupKey(string $level, string $message, ?string $exceptionClass, ?string $location): string
    {
        if ($exceptionClass === null) {
            return "{$level}|{$message}";
        }

        if (str_starts_with((string) $location, self::VENDOR_DIRECTORY)) {
            return "{$exceptionClass}|{$location}|{$message}";
        }

        return "{$exceptionClass}|{$location}";
    }

    private function normalize(string $text): string
    {
        $normalized = preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.)*'/",
                '/"(?:[^"\\\\]|\\\\.)*"/',
                '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/',
                '/(?<!\w)\d+(?:[.,\/-]\d+)+(?!\w)/',
                '/(?<![\w.])(?<!SQLSTATE\[)-?\d+(?:\.\d+)?(?![\w.])/',
                '/\(\s*\?(?:\s*,\s*\?)*\s*\)/',
                '/\s+/',
            ],
            ['?', '?', '?', '?', '?', '(?)', ' '],
            str_replace(base_path().'/', '', $text),
        );

        return trim((string) $normalized);
    }
}
