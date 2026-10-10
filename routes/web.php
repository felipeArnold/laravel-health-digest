<?php

declare(strict_types=1);

use FelipeArnold\HealthDigest\Http\Controllers\SlowQueriesController;
use Illuminate\Support\Facades\Route;

$dashboardPath = trim((string) config('health-digest.dashboard.path'), '/');

Route::middleware(config('health-digest.dashboard.middleware'))
    ->prefix($dashboardPath)
    ->name('health-digest.')
    ->group(function () use ($dashboardPath): void {
        Route::redirect('/', "/{$dashboardPath}/slow-queries")->name('index');
        Route::get('slow-queries', SlowQueriesController::class)->name('slow-queries');
    });
