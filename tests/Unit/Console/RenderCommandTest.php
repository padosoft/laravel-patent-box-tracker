<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Console;

use Padosoft\PatentBoxTracker\Models\TrackedCommit;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class RenderCommandTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runPackageMigrations();

        $this->outputDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'patent-box-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            foreach (glob($this->outputDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->outputDir);
        }

        parent::tearDown();
    }

    public function test_command_renders_json_dossier_and_records_audit_row(): void
    {
        $session = $this->seedSession();
        $outFile = $this->outputDir.DIRECTORY_SEPARATOR.'session-'.$session->id.'.json';

        $exitCode = $this->artisan('patent-box:render', [
            'session' => $session->id,
            '--format' => 'json',
            '--locale' => 'it',
            '--out' => $outFile,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outFile);

        $bytes = file_get_contents($outFile);
        $this->assertNotFalse($bytes);
        $this->assertGreaterThan(0, strlen($bytes));

        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('hash_chain', $decoded);
        $this->assertArrayHasKey('summary', $decoded);

        /** @var TrackedDossier|null $audit */
        $audit = TrackedDossier::query()
            ->where('tracking_session_id', $session->id)
            ->where('format', 'json')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('json', $audit->format);
        $this->assertSame('it', $audit->locale);
        $this->assertSame($outFile, $audit->path);
        $this->assertSame(strlen($bytes), $audit->byte_size);
        $this->assertSame(hash('sha256', $bytes), $audit->sha256);
    }

    public function test_command_returns_one_for_invalid_format(): void
    {
        $session = $this->seedSession();

        $exitCode = $this->artisan('patent-box:render', [
            'session' => $session->id,
            '--format' => 'xlsx',
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_command_returns_one_for_unsupported_locale(): void
    {
        $session = $this->seedSession();

        $exitCode = $this->artisan('patent-box:render', [
            'session' => $session->id,
            '--format' => 'json',
            '--locale' => 'en',
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_command_returns_one_for_unknown_session(): void
    {
        $exitCode = $this->artisan('patent-box:render', [
            'session' => 999999,
            '--format' => 'json',
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_command_renders_pdf_via_dompdf_when_available(): void
    {
        if (! class_exists('\\Dompdf\\Dompdf')) {
            $this->markTestSkipped('dompdf/dompdf is not installed.');
        }

        $this->app['config']->set('patent-box-tracker.renderer.driver', 'dompdf');

        $session = $this->seedSession();
        $outFile = $this->outputDir.DIRECTORY_SEPARATOR.'session-'.$session->id.'.pdf';

        $exitCode = $this->artisan('patent-box:render', [
            'session' => $session->id,
            '--format' => 'pdf',
            '--locale' => 'it',
            '--out' => $outFile,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outFile);

        $bytes = (string) file_get_contents($outFile);
        $this->assertSame('%PDF-', substr($bytes, 0, 5));

        /** @var TrackedDossier|null $audit */
        $audit = TrackedDossier::query()
            ->where('tracking_session_id', $session->id)
            ->where('format', 'pdf')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(strlen($bytes), $audit->byte_size);
        $this->assertSame(hash('sha256', $bytes), $audit->sha256);
    }

    public function test_command_creates_output_directory_when_missing(): void
    {
        $session = $this->seedSession();

        $nestedDir = $this->outputDir.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'deeper';
        $outFile = $nestedDir.DIRECTORY_SEPARATOR.'dossier.json';

        $this->assertDirectoryDoesNotExist($nestedDir);

        $exitCode = $this->artisan('patent-box:render', [
            'session' => $session->id,
            '--format' => 'json',
            '--out' => $outFile,
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outFile);
    }

    private function seedSession(): TrackingSession
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

        $shas = [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40)];
        foreach ($shas as $i => $sha) {
            TrackedCommit::create([
                'tracking_session_id' => $session->id,
                'repository_path' => '/repos/x',
                'repository_role' => 'primary_ip',
                'sha' => $sha,
                'author_name' => 'Lorenzo',
                'author_email' => 'l@example.com',
                'committed_at' => sprintf('2026-02-%02d 10:00:00', $i + 1),
                'message' => 'feat: '.($i + 1),
                'phase' => 'implementation',
                'is_rd_qualified' => true,
                'rd_qualification_confidence' => 0.9,
                'rationale' => 'Test row '.($i + 1).'.',
                'ai_attribution' => 'human',
            ]);
        }

        return $session;
    }
}
