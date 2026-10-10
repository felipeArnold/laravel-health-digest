<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest;

use FelipeArnold\HealthDigest\Console\HealthDigestCommand;
use FelipeArnold\HealthDigest\Http\Middleware\LogSlowRequests;
use FelipeArnold\HealthDigest\Listeners\LogSlowQuery;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Monolog\Formatter\JsonFormatter;

class HealthDigestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/health-digest.php', 'health-digest');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/health-digest.php' => config_path('health-digest.php'),
        ], 'health-digest-config');

        $this->registerLogChannel();
        $this->registerDashboard();

        Event::listen(QueryExecuted::class, [LogSlowQuery::class, 'handle']);
        Event::listen(JobProcessing::class, [LogSlowQuery::class, 'attributeToJob']);
        Event::listen(CommandStarting::class, [LogSlowQuery::class, 'attributeToCommand']);

        $this->callAfterResolving(Kernel::class, $this->registerMiddleware(...));

        if ($this->app->runningInConsole()) {
            $this->commands([HealthDigestCommand::class]);
        }
    }

    private function registerMiddleware(Kernel $kernel): void
    {
        if (! $kernel instanceof HttpKernel) {
            return;
        }

        $availableGroups = array_keys($kernel->getMiddlewareGroups());

        foreach (array_intersect((array) config('health-digest.middleware_groups'), $availableGroups) as $group) {
            $kernel->prependMiddlewareToGroup($group, LogSlowRequests::class);
        }
    }

    private function registerDashboard(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'health-digest');

        if (! Gate::has(HealthDigest::DASHBOARD_GATE)) {
            Gate::define(HealthDigest::DASHBOARD_GATE, fn ($user = null): bool => $this->app->environment('local'));
        }

        if (config('health-digest.dashboard.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }
    }

    private function registerLogChannel(): void
    {
        $channelKey = 'logging.channels.'.HealthDigest::channel();

        if (config()->has($channelKey)) {
            return;
        }

        config()->set($channelKey, [
            'driver' => 'daily',
            'path' => HealthDigest::slowLogPath(),
            'level' => 'warning',
            'days' => (int) config('health-digest.retention_days'),
            'formatter' => JsonFormatter::class,
            'replace_placeholders' => false,
        ]);
    }
}
