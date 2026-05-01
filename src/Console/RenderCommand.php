<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Padosoft\PatentBoxTracker\Models\TrackedDossier;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Padosoft\PatentBoxTracker\Renderers\DossierRenderer;
use Padosoft\PatentBoxTracker\Renderers\JsonDossierRenderer;
use Padosoft\PatentBoxTracker\Renderers\PdfDossierRenderer;
use Padosoft\PatentBoxTracker\Renderers\RenderException;
use Throwable;

/**
 * `php artisan patent-box:render <session-id> --format=pdf --out=...`
 *
 * Resolves a {@see TrackingSession}, picks the matching renderer
 * (PDF / JSON), persists the rendered bytes to disk, and inserts a
 * {@see TrackedDossier} audit row recording byte size + SHA-256 of
 * the artefact.
 *
 * Exit codes:
 *   - 0 on success.
 *   - 1 on validation error (bad format, bad locale, missing
 *     session, missing/unwritable output path).
 *   - 2 on render exception (engine failure, missing renderer
 *     dependency, etc.). The exception message is printed to
 *     stderr so a CI run can surface it without trawling logs.
 */
final class RenderCommand extends Command
{
    /** @var string */
    protected $signature = 'patent-box:render
        {session : The TrackingSession id to render.}
        {--format=pdf : Output format (pdf|json).}
        {--locale=it : Locale for the rendered output (only `it` supported in v0.1).}
        {--out= : Output file path; defaults to storage/dossiers/<session-id>.<format>.}';

    /** @var string */
    protected $description = 'Render a Patent Box tracking session into a PDF dossier or JSON sidecar.';

    public const SUPPORTED_FORMATS = ['pdf', 'json'];

    public const SUPPORTED_LOCALES = ['it'];

    public function handle(): int
    {
        $sessionId = $this->resolveSessionId();
        if ($sessionId === null) {
            return 1;
        }

        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, self::SUPPORTED_FORMATS, true)) {
            $this->error(sprintf(
                'Unsupported --format "%s". Allowed: %s.',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));

            return 1;
        }

        $locale = strtolower((string) $this->option('locale'));
        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $this->error(sprintf(
                'Unsupported --locale "%s". Allowed: %s. (English locale lands in v0.2.)',
                $locale,
                implode(', ', self::SUPPORTED_LOCALES),
            ));

            return 1;
        }

        try {
            /** @var TrackingSession $session */
            $session = TrackingSession::query()->findOrFail($sessionId);
        } catch (ModelNotFoundException) {
            $this->error(sprintf('TrackingSession #%d not found.', $sessionId));

            return 1;
        }

        $outPath = $this->resolveOutPath($sessionId, $format);

        $renderer = $this->resolveRenderer($format, $locale);

        try {
            $rendered = $renderer->render($session);
            $rendered->save($outPath);
        } catch (RenderException $exception) {
            $this->error(sprintf('Render failed: %s', $exception->getMessage()));

            return 2;
        } catch (Throwable $exception) {
            $this->error(sprintf('Unexpected render error: %s', $exception->getMessage()));

            return 2;
        }

        TrackedDossier::query()->create([
            'tracking_session_id' => $session->id,
            'format' => $format,
            'locale' => $locale,
            'path' => $outPath,
            'byte_size' => $rendered->byteSize,
            'sha256' => $rendered->sha256,
            'generated_at' => now(),
        ]);

        $this->info(sprintf(
            'Rendered dossier (%s, %s) to %s — %d bytes — sha256:%s',
            $format,
            $locale,
            $outPath,
            $rendered->byteSize,
            $rendered->sha256,
        ));

        return 0;
    }

    private function resolveSessionId(): ?int
    {
        $raw = $this->argument('session');
        if (! is_string($raw) && ! is_int($raw)) {
            $this->error('Session id is required.');

            return null;
        }

        $id = (int) $raw;
        if ($id <= 0) {
            $this->error(sprintf('Invalid session id "%s" (must be a positive integer).', (string) $raw));

            return null;
        }

        return $id;
    }

    private function resolveOutPath(int $sessionId, string $format): string
    {
        $explicit = $this->option('out');
        if (is_string($explicit) && trim($explicit) !== '') {
            return $explicit;
        }

        $base = function_exists('storage_path')
            ? storage_path('dossiers')
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'patent-box-dossiers';

        return $base.DIRECTORY_SEPARATOR.$sessionId.'.'.$format;
    }

    private function resolveRenderer(string $format, string $locale): DossierRenderer
    {
        // Push the CLI locale into config before resolving so the
        // container-bound renderer picks it up at instantiation time.
        // Both JsonDossierRenderer and PdfDossierRenderer read
        // `patent-box-tracker.locale` from config during `bind()`.
        $this->getLaravel()['config']->set('patent-box-tracker.locale', $locale);

        /** @var DossierRenderer $renderer */
        $renderer = match ($format) {
            'pdf' => $this->getLaravel()->make(PdfDossierRenderer::class),
            'json' => $this->getLaravel()->make(JsonDossierRenderer::class),
            default => throw new \InvalidArgumentException(sprintf('Unsupported format "%s".', $format)),
        };

        return $renderer;
    }
}
