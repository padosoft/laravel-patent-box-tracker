<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use Illuminate\Support\Facades\App;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

/**
 * Fluent helper returned by {@see TrackingSession::renderDossier()}.
 *
 * Wraps the PDF + JSON renderers in a chainable shape so the public
 * API mirrors the README quick-start verbatim:
 *
 *     $session->renderDossier()
 *         ->locale('it')
 *         ->toPdf()
 *         ->save(storage_path('dossier-2026.pdf'));
 *
 *     $session->renderDossier()->toJson()->save(storage_path('dossier-2026.json'));
 *
 * `locale()` mutates the builder's local state; `toPdf()` /
 * `toJson()` resolve the corresponding renderer through the
 * container (so test bindings swap the engine cleanly) and return
 * the {@see RenderedDossier} produced for this session.
 */
final class DossierRenderBuilder
{
    private string $locale = 'it';

    public function __construct(private readonly TrackingSession $session) {}

    public function locale(string $locale): self
    {
        $allowed = ['it'];
        if (! in_array($locale, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'DossierRenderBuilder: locale must be one of [%s] in v0.1, got "%s". '
                .'English locale lands in v0.2.',
                implode(', ', $allowed),
                $locale,
            ));
        }
        $this->locale = $locale;

        return $this;
    }

    public function toPdf(): RenderedDossier
    {
        $this->applyLocale();
        /** @var PdfDossierRenderer $renderer */
        $renderer = App::make(PdfDossierRenderer::class);

        return $renderer->render($this->session);
    }

    public function toJson(): RenderedDossier
    {
        $this->applyLocale();
        /** @var JsonDossierRenderer $renderer */
        $renderer = App::make(JsonDossierRenderer::class);

        return $renderer->render($this->session);
    }

    private function applyLocale(): void
    {
        $config = App::make('config');
        $config->set('patent-box-tracker.locale', $this->locale);
    }
}
