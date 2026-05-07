<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\PatentBoxTracker\Api\ApiResponse;
use Padosoft\PatentBoxTracker\Renderers\RendererCapabilities;

final class CapabilitiesController extends Controller
{
    public function __invoke(RendererCapabilities $rendererCapabilities): JsonResponse
    {
        $classifier = (array) config('patent-box-tracker.classifier', []);
        $renderer = (array) config('patent-box-tracker.renderer', []);

        return ApiResponse::success([
            'package' => [
                'name' => 'padosoft/laravel-patent-box-tracker',
                'api_version' => 'v1',
            ],
            'roles' => ['primary_ip', 'support', 'meta_self'],
            'regimes' => ['documentazione_idonea', 'non_documentazione'],
            'render_formats' => ['pdf', 'json'],
            'locales' => ['it'],
            'classifier' => [
                'provider' => (string) ($classifier['driver'] ?? 'regolo'),
                'model' => (string) ($classifier['model'] ?? 'claude-sonnet-4-6'),
                'seed' => (int) ($classifier['seed'] ?? 0),
                'batch_size' => (int) ($classifier['batch_size'] ?? 20),
                'cost_cap_eur_per_run' => (float) ($classifier['cost_cap_eur_per_run'] ?? 50.0),
            ],
            'renderer' => [
                'driver' => (string) ($renderer['driver'] ?? 'browsershot'),
                'available_drivers' => $this->availableDrivers($rendererCapabilities),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function availableDrivers(RendererCapabilities $rendererCapabilities): array
    {
        $drivers = [];
        if ($rendererCapabilities->browsershotAvailable()) {
            $drivers[] = 'browsershot';
        }
        if ($rendererCapabilities->dompdfAvailable()) {
            $drivers[] = 'dompdf';
        }

        return $drivers;
    }
}
