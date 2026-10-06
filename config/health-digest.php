<?php

declare(strict_types=1);

return [

    /*
    | Set to false to stop logging slow queries and slow requests.
    */
    'enabled' => (bool) env('HEALTH_DIGEST_ENABLED', true),

    /*
    | Log channel that receives slow query and slow request entries as JSON.
    | When the channel is not defined in config/logging.php the package
    | registers it as a daily JSON log at "log_path".
    */
    'channel' => 'health-digest',

    'log_path' => env('HEALTH_DIGEST_LOG_PATH'),

    'retention_days' => (int) env('HEALTH_DIGEST_RETENTION_DAYS', 14),

    /*
    | Thresholds in milliseconds. Zero disables the corresponding log.
    */
    'query_threshold_ms' => (int) env('HEALTH_DIGEST_QUERY_MS', 500),

    'request_threshold_ms' => (int) env('HEALTH_DIGEST_REQUEST_MS', 3000),

    /*
    | Middleware groups that measure request time. Use [] to register
    | LogSlowRequests manually.
    */
    'middleware_groups' => ['web'],

    /*
    | Application error log read by the digest. Both the single file
    | (laravel.log) and its daily siblings (laravel-YYYY-MM-DD.log) are read.
    | Null means storage/logs/laravel.log.
    */
    'error_log_path' => env('HEALTH_DIGEST_ERROR_LOG_PATH'),

    /*
    | Where health:digest writes its JSON. Null means
    | storage/app/health-digest/latest.json.
    */
    'output_path' => env('HEALTH_DIGEST_OUTPUT_PATH'),

];
