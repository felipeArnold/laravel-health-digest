<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Console;

use FelipeArnold\HealthDigest\Actions\BuildDigest;
use FelipeArnold\HealthDigest\HealthDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class HealthDigestCommand extends Command
{
    protected $signature = 'health:digest
        {--hours=12 : Time window to analyse, in hours}
        {--limit=20 : Maximum items per section}';

    protected $description = 'Group recent slow queries, slow requests and errors into a ranked JSON digest';

    public function handle(BuildDigest $buildDigest): int
    {
        $digest = $buildDigest->handle(now()->subHours((int) $this->option('hours')), (int) $this->option('limit'));

        $outputPath = HealthDigest::outputPath();

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        $this->components->info(sprintf(
            'Digest written to %s: %d slow quer%s, %d slow request(s), %d error(s).',
            $outputPath,
            count($digest['slow_queries']),
            count($digest['slow_queries']) === 1 ? 'y' : 'ies',
            count($digest['slow_requests']),
            count($digest['errors']),
        ));

        return self::SUCCESS;
    }
}
