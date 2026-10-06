<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Enums;

enum DigestSection: string
{
    case SlowQueries = 'slow_queries';
    case SlowRequests = 'slow_requests';
    case Errors = 'errors';
}
