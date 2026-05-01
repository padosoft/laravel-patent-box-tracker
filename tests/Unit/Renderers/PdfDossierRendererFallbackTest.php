<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Renderers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Renderers\DefaultRendererCapabilities;
use Padosoft\PatentBoxTracker\Renderers\DossierPayloadAssembler;
use Padosoft\PatentBoxTracker\Renderers\MissingRendererDependencyException;
use Padosoft\PatentBoxTracker\Renderers\PdfDossierRenderer;
use Padosoft\PatentBoxTracker\Renderers\RendererCapabilities;
use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * Verifies the renderer surfaces a clear MissingRendererDependencyException
 * when neither Browsershot nor DomPDF is available.
 *
 * Both engines are `require-dev` in this repo so the actual
 * fallback branch never fires under normal PHPUnit. We exercise it
 * via a stub {@see RendererCapabilities} that reports both engines
 * as missing — which is the production code path on a fresh
 * consumer install that opted out of both.
 */
final class PdfDossierRendererFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();
    }

    public function test_render_throws_missing_dependency_when_neither_engine_available(): void
    {
        $session = $this->seedSession();

        $renderer = $this->buildRendererWithCapabilities(
            $this->stubCapabilities(false, false),
        );

        $this->expectException(MissingRendererDependencyException::class);
        $this->expectExceptionMessageMatches('/spatie\/browsershot/');
        $renderer->render($session);
    }

    public function test_missing_dependency_message_lists_both_install_commands(): void
    {
        $session = $this->seedSession();

        $renderer = $this->buildRendererWithCapabilities(
            $this->stubCapabilities(false, false),
        );

        try {
            $renderer->render($session);
            $this->fail('Expected MissingRendererDependencyException');
        } catch (MissingRendererDependencyException $exception) {
            $this->assertStringContainsString('spatie/browsershot', $exception->getMessage());
            $this->assertStringContainsString('dompdf/dompdf', $exception->getMessage());
            $this->assertStringContainsString('JsonDossierRenderer', $exception->getMessage());
        }
    }

    public function test_default_capabilities_report_real_class_existence(): void
    {
        $defaults = new DefaultRendererCapabilities;

        $this->assertSame(class_exists('\\Spatie\\Browsershot\\Browsershot'), $defaults->browsershotAvailable());
        $this->assertSame(class_exists('\\Dompdf\\Dompdf'), $defaults->dompdfAvailable());
    }

    public function test_render_falls_back_to_dompdf_when_only_dompdf_installed(): void
    {
        if (! class_exists('\\Dompdf\\Dompdf')) {
            $this->markTestSkipped('dompdf/dompdf is not installed.');
        }

        $session = $this->seedSession();

        // Configured driver: browsershot, but only DomPDF available
        // → fall back to DomPDF instead of throwing.
        $this->app['config']->set('patent-box-tracker.renderer.driver', PdfDossierRenderer::DRIVER_BROWSERSHOT);

        $renderer = $this->buildRendererWithCapabilities(
            $this->stubCapabilities(false, true),
        );

        $rendered = $renderer->render($session);

        $this->assertSame('pdf', $rendered->format);
        $this->assertGreaterThan(0, $rendered->byteSize);
        $this->assertSame('%PDF-', substr($rendered->contents, 0, 5));
    }

    private function stubCapabilities(bool $browsershot, bool $dompdf): RendererCapabilities
    {
        return new class($browsershot, $dompdf) implements RendererCapabilities
        {
            public function __construct(
                private readonly bool $browsershot,
                private readonly bool $dompdf,
            ) {}

            public function browsershotAvailable(): bool
            {
                return $this->browsershot;
            }

            public function dompdfAvailable(): bool
            {
                return $this->dompdf;
            }
        };
    }

    private function buildRendererWithCapabilities(RendererCapabilities $capabilities): PdfDossierRenderer
    {
        return new PdfDossierRenderer(
            assembler: $this->app->make(DossierPayloadAssembler::class),
            viewFactory: $this->app->make(ViewFactory::class),
            capabilities: $capabilities,
            config: ['driver' => PdfDossierRenderer::DRIVER_BROWSERSHOT],
            locale: 'it',
        );
    }

    private function seedSession(): TrackingSession
    {
        return TrackingSession::create([
            'tax_identity_json' => [
                'denomination' => 'Padosoft di Lorenzo Padovani',
                'p_iva' => 'IT01234567890',
                'fiscal_year' => '2026',
                'regime' => 'documentazione_idonea',
            ],
            'period_from' => '2026-01-01 00:00:00',
            'period_to' => '2026-12-31 23:59:59',
            'cost_model_json' => ['hourly_rate_eur' => 80],
            'status' => TrackingSession::STATUS_CLASSIFIED,
        ]);
    }
}
