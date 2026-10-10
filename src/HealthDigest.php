<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest;

use Closure;
use Throwable;

class HealthDigest
{
    public const string ORIGIN_CONTEXT_KEY = 'health_digest_origin';

    public const string DASHBOARD_GATE = 'viewHealthDigest';

    private static ?Closure $tenantResolver = null;

    /** @param  Closure(): (string|int|null)  $resolver */
    public static function resolveTenantUsing(?Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    public static function currentTenant(): ?string
    {
        if (self::$tenantResolver === null) {
            return null;
        }

        try {
            $tenant = (self::$tenantResolver)();
        } catch (Throwable) {
            return null;
        }

        return is_scalar($tenant) ? (string) $tenant : null;
    }

    public static function channel(): string
    {
        return (string) config('health-digest.channel');
    }

    public static function slowLogPath(): string
    {
        return config('logging.channels.'.self::channel().'.path')
            ?: config('health-digest.log_path')
            ?: storage_path('logs/health-digest.log');
    }

    public static function errorLogPath(): string
    {
        return config('health-digest.error_log_path') ?: storage_path('logs/laravel.log');
    }

    public static function outputPath(): string
    {
        return config('health-digest.output_path') ?: storage_path('app/health-digest/latest.json');
    }
}
