<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Http\Middleware;

use Closure;
use FelipeArnold\HealthDigest\Enums\DigestSection;
use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogSlowRequests
{
    private const int NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private const int ORIGIN_MAX_LENGTH = 300;

    private const string LIVEWIRE_HEADER = 'X-Livewire';

    private const string UNKNOWN_COMPONENT = 'unknown';

    private const string COMPONENT_NAME_PATTERN = '/^[\w.:\-]{1,120}$/';

    private const string METHOD_NAME_PATTERN = '/^\$?\w{1,60}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $origin = $this->describeOrigin($request);

        Context::addHidden(HealthDigest::ORIGIN_CONTEXT_KEY, $origin);

        $response = $next($request);

        $this->logWhenSlow($origin, $response, (hrtime(true) - $startedAt) / self::NANOSECONDS_PER_MILLISECOND);

        return $response;
    }

    private function logWhenSlow(string $origin, Response $response, float $elapsedMilliseconds): void
    {
        $thresholdInMilliseconds = (int) config('health-digest.request_threshold_ms');

        if (! config('health-digest.enabled') || $thresholdInMilliseconds <= 0 || $elapsedMilliseconds < $thresholdInMilliseconds) {
            return;
        }

        Log::channel(HealthDigest::channel())->warning('slow_request', [
            'type' => DigestSection::SlowRequests->value,
            'origin' => $origin,
            'status' => $response->getStatusCode(),
            'time_ms' => (int) round($elapsedMilliseconds),
            'tenant' => HealthDigest::currentTenant(),
        ]);
    }

    private function describeOrigin(Request $request): string
    {
        $components = $request->input('components');

        if (! $request->hasHeader(self::LIVEWIRE_HEADER) || ! is_array($components)) {
            return $request->method().' '.($request->route()?->uri() ?? $request->path());
        }

        $componentNames = collect($components)->map(fn (mixed $component): string => $this->describeComponent($component));

        return Str::limit('livewire '.$componentNames->implode(', '), self::ORIGIN_MAX_LENGTH);
    }

    private function describeComponent(mixed $component): string
    {
        $snapshot = data_get($component, 'snapshot');
        $name = data_get(is_string($snapshot) ? json_decode($snapshot, true) : null, 'memo.name');

        if (! $this->matches(self::COMPONENT_NAME_PATTERN, $name)) {
            return self::UNKNOWN_COMPONENT;
        }

        $methods = collect(data_get($component, 'calls'))
            ->pluck('method')
            ->filter(fn (mixed $method): bool => $this->matches(self::METHOD_NAME_PATTERN, $method))
            ->implode('|');

        return $methods === '' ? $name : "{$name}@{$methods}";
    }

    private function matches(string $pattern, mixed $value): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }
}
