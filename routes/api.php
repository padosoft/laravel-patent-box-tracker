<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\CapabilitiesController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackedCommitsController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackedDossiersController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackedEvidenceController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackingSessionsController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\HealthController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ShowTrackingSessionController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\VerifySessionIntegrityController;

$prefix = trim((string) config('patent-box-tracker.api.prefix', 'api/patent-box'), '/');
$middleware = (array) config('patent-box-tracker.api.middleware', ['api']);
$rateLimiter = (string) config('patent-box-tracker.api.rate_limiter', 'api');
if ($rateLimiter !== '') {
    $middleware[] = sprintf('throttle:%s', $rateLimiter);
}
$middleware[] = SubstituteBindings::class;
$middleware = array_values(array_unique($middleware));

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function (): void {
        Route::prefix('v1')->group(function (): void {
            Route::get('/health', HealthController::class);
            Route::get('/capabilities', CapabilitiesController::class);
            Route::get('/tracking-sessions', ListTrackingSessionsController::class);
            Route::get('/tracking-sessions/{trackingSession}', ShowTrackingSessionController::class);
            Route::get('/tracking-sessions/{trackingSession}/commits', ListTrackedCommitsController::class);
            Route::get('/tracking-sessions/{trackingSession}/evidence', ListTrackedEvidenceController::class);
            Route::get('/tracking-sessions/{trackingSession}/dossiers', ListTrackedDossiersController::class);
            Route::get('/tracking-sessions/{trackingSession}/integrity', VerifySessionIntegrityController::class);
        });
    });
