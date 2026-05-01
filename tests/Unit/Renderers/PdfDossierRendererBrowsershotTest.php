<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Renderers;

use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Renderers\PdfDossierRenderer;
use Padosoft\PatentBoxTracker\Tests\TestCase;

/**
 * Live-Chromium-required Browsershot smoke test. Self-skips when
 * Browsershot is not installed OR the runtime cannot find a Chrome
 * binary — CI runners without Puppeteer / headless Chromium hit
 * the latter path and skip cleanly.
 *
 * The happy path produces a PDF byte-string whose first 5 bytes are
 * `%PDF-` (the PDF header magic). We deliberately do NOT parse the
 * PDF further here — the structural validity of the output is the
 * Chromium engine's responsibility, not ours. R14 enforcement (no
 * empty body on success) is exercised via assertions on byte size.
 */
final class PdfDossierRendererBrowsershotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('\\Spatie\\Browsershot\\Browsershot')) {
            $this->markTestSkipped('spatie/browsershot is not installed in this environment.');
        }

        if (getenv('PATENT_BOX_BROWSERSHOT_LIVE') !== '1') {
            $this->markTestSkipped(
                'PATENT_BOX_BROWSERSHOT_LIVE=1 is not set. Set it to run the Browsershot smoke test '
                .'against a real Chromium runtime; otherwise the test is skipped to avoid '
                .'flakiness on CI runners without Puppeteer.',
            );
        }

        $this->runPackageMigrations();
    }

    public function test_browsershot_engine_renders_pdf_with_pdf_header(): void
    {
        $this->app['config']->set('patent-box-tracker.renderer.driver', PdfDossierRenderer::DRIVER_BROWSERSHOT);

        $session = $this->seedMinimalSession();

        /** @var PdfDossierRenderer $renderer */
        $renderer = $this->app->make(PdfDossierRenderer::class);
        $rendered = $renderer->render($session);

        $this->assertSame('pdf', $rendered->format);
        $this->assertSame('it', $rendered->locale);
        $this->assertGreaterThan(0, $rendered->byteSize);
        $this->assertSame(strlen($rendered->contents), $rendered->byteSize);
        $this->assertSame('%PDF-', substr($rendered->contents, 0, 5));
    }

    private function seedMinimalSession(): TrackingSession
    {
        $session = TrackingSession::create([
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

        TrackedCommit::create([
            'tracking_session_id' => $session->id,
            'repository_path' => '/repos/x',
            'repository_role' => 'primary_ip',
            'sha' => str_repeat('a', 40),
            'author_name' => 'Lorenzo',
            'author_email' => 'l@example.com',
            'committed_at' => '2026-02-01 10:00:00',
            'message' => 'feat: x',
            'phase' => 'implementation',
            'is_rd_qualified' => true,
            'rd_qualification_confidence' => 0.9,
            'rationale' => 'Test row.',
            'ai_attribution' => 'human',
        ]);

        return $session;
    }
}
