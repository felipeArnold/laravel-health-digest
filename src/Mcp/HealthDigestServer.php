<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Mcp;

use FelipeArnold\HealthDigest\Mcp\Tools\ListProblems;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Health Digest')]
#[Version('1.0.0')]
#[Instructions('Reports what is going wrong in the application this server runs on: errors from the application log, slow queries and slow requests, grouped by fingerprint and ranked by impact. Call list-problems first, then open the code at each item location.')]
class HealthDigestServer extends Server
{
    protected array $tools = [
        ListProblems::class,
    ];
}
