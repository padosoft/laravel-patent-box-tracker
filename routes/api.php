<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\CapabilitiesController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\DownloadTrackedDossierController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\HealthController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackedCommitsController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackedDossiersController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackedEvidenceController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ListTrackingSessionsController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\QueueRenderDossierController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\QueueTrackingSessionController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ShowTrackingSessionController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\TrackingDryRunController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\ValidateRepositoryController;
use Padosoft\PatentBoxTracker\Http\Controllers\Api\V1\VerifySessionIntegrityController;
use Padosoft\PatentBoxTracker\Http\Middleware\HandleApiErrors;
use Padosoft\PatentBoxTracker\Http\Middleware\ProtectPatentBoxApi;

$prefix = trim((string) config('patent-box-tracker.api.prefix', 'api/patent-box'), '/');
$middleware = [HandleApiErrors::class, ProtectPatentBoxApi::class];
$configuredMiddleware = (array) config('patent-box-tracker.api.middleware', []);
$rateLimiter = (string) config('patent-box-tracker.api.rate_limiter', 'api');

$middleware = array_merge($middleware, $configuredMiddleware);
if ($rateLimiter !== '') {
    if (RateLimiter::limiter($rateLimiter) !== null) {
        $middleware[] = 'throttle:'.$rateLimiter;
    }
}
$middleware[] = SubstituteBindings::class;
$middleware = array_values(array_unique($middleware));

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function (): void {
        Route::prefix('v1')->group(function (): void {
            Route::get('/health', HealthController::class);
            Route::get('/capabilities', CapabilitiesController::class);
            Route::post('/repositories/validate', ValidateRepositoryController::class);
            Route::post('/tracking-sessions/dry-run', TrackingDryRunController::class);
            Route::post('/tracking-sessions', QueueTrackingSessionController::class);
            Route::get('/tracking-sessions', ListTrackingSessionsController::class);
            Route::get('/tracking-sessions/{trackingSession}', ShowTrackingSessionController::class);
            Route::get('/tracking-sessions/{trackingSession}/commits', ListTrackedCommitsController::class);
            Route::get('/tracking-sessions/{trackingSession}/evidence', ListTrackedEvidenceController::class);
            Route::get('/tracking-sessions/{trackingSession}/dossiers', ListTrackedDossiersController::class);
            Route::post('/tracking-sessions/{trackingSession}/dossiers', QueueRenderDossierController::class);
            Route::get('/tracking-sessions/{trackingSession}/dossiers/{dossier}/download', DownloadTrackedDossierController::class);
            Route::get('/tracking-sessions/{trackingSession}/integrity', VerifySessionIntegrityController::class);
        });
    });
