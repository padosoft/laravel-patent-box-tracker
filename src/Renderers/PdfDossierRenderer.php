<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Padosoft\PatentBoxTracker\Models\TrackingSession;
use Throwable;

/**
 * PDF dossier renderer.
 *
 * Engine selection:
 *   - `browsershot` (default) — Chromium-backed, highest fidelity,
 *     requires the `spatie/browsershot` package + a working
 *     headless Chrome.
 *   - `dompdf` — pure-PHP fallback for environments without
 *     Chromium. Lower fidelity (no JS, simpler CSS) but no native
 *     dependency, useful for CI runners and air-gapped servers.
 *
 * Engine pick order at render time:
 *   1. `config('patent-box-tracker.renderer.driver')` — operator
 *      override.
 *   2. If browsershot is available, browsershot.
 *   3. If dompdf is available, dompdf.
 *   4. Throw {@see MissingRendererDependencyException}.
 *
 * Failure surface:
 *   - Engine throws → wrap in {@see RenderException}; never return
 *     `''` on engine failure (R14 — surface failures loudly).
 *   - Engine returns non-string / 0 bytes → throw
 *     {@see RenderException}; a 0-byte PDF on disk while the call
 *     site logs "rendered ok" is the regression PR #27 shipped on
 *     AskMyDocs and we are not repeating it.
 */
final class PdfDossierRenderer implements DossierRenderer
{
    public const DRIVER_BROWSERSHOT = 'browsershot';

    public const DRIVER_DOMPDF = 'dompdf';

    /**
     * @param  array<string, mixed>  $config  patent-box-tracker.renderer config slice
     */
    public function __construct(
        private readonly DossierPayloadAssembler $assembler,
        private readonly ViewFactory $viewFactory,
        private readonly RendererCapabilities $capabilities,
        private readonly array $config = [],
        private readonly string $locale = 'it',
    ) {}

    public function format(): string
    {
        return 'pdf';
    }

    public function render(TrackingSession $session): RenderedDossier
    {
        $payload = $this->assembler->assemble($session);
        $html = $this->renderHtml($payload);

        $driver = $this->pickDriver();

        $bytes = match ($driver) {
            self::DRIVER_BROWSERSHOT => $this->renderWithBrowsershot($html),
            self::DRIVER_DOMPDF => $this->renderWithDomPdf($html),
            default => throw new RenderException(sprintf(
                'Unknown PDF driver "%s". Allowed: %s, %s.',
                $driver,
                self::DRIVER_BROWSERSHOT,
                self::DRIVER_DOMPDF,
            )),
        };

        if ($bytes === '') {
            throw new RenderException(sprintf(
                'PDF renderer "%s" returned an empty body. Refusing to ship a zero-byte PDF.',
                $driver,
            ));
        }

        return new RenderedDossier(
            contents: $bytes,
            sha256: hash('sha256', $bytes),
            byteSize: strlen($bytes),
            format: 'pdf',
            locale: $this->locale,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderHtml(array $payload): string
    {
        $hashHead = (string) ($payload['hash_chain']['head'] ?? '');

        $view = $this->viewFactory->make(
            'patent-box-tracker::pdf.it.dossier',
            [
                'payload' => $payload,
                'taxIdentity' => (array) ($payload['tax_identity'] ?? []),
                'reportingPeriod' => (array) ($payload['reporting_period'] ?? []),
                'summary' => (array) ($payload['summary'] ?? []),
                'repositories' => (array) ($payload['repositories'] ?? []),
                'commits' => (array) ($payload['commits'] ?? []),
                'evidenceLinks' => (array) ($payload['evidence_links'] ?? []),
                'ipOutputs' => (array) ($payload['ip_outputs'] ?? []),
                'hashChain' => (array) ($payload['hash_chain'] ?? []),
                'hashHead' => $hashHead,
                'generatedAt' => (string) ($payload['generated_at'] ?? ''),
            ],
        );

        return (string) $view->render();
    }

    private function pickDriver(): string
    {
        $configured = (string) ($this->config['driver'] ?? self::DRIVER_BROWSERSHOT);

        if ($configured === self::DRIVER_BROWSERSHOT && $this->capabilities->browsershotAvailable()) {
            return self::DRIVER_BROWSERSHOT;
        }

        if ($configured === self::DRIVER_DOMPDF && $this->capabilities->dompdfAvailable()) {
            return self::DRIVER_DOMPDF;
        }

        // Fall back across the available engines so a misconfigured
        // driver string does not break the dossier when an alternative
        // engine is installed.
        if ($this->capabilities->browsershotAvailable()) {
            return self::DRIVER_BROWSERSHOT;
        }

        if ($this->capabilities->dompdfAvailable()) {
            return self::DRIVER_DOMPDF;
        }

        throw new MissingRendererDependencyException(
            'No PDF renderer engine available. Install one of: '
            .'`composer require --dev spatie/browsershot` (requires headless Chromium) '
            .'or `composer require --dev dompdf/dompdf` (pure-PHP fallback). '
            .'For environments without either, use the JsonDossierRenderer instead.',
        );
    }

    private function renderWithBrowsershot(string $html): string
    {
        if (! $this->capabilities->browsershotAvailable()) {
            throw new MissingRendererDependencyException(
                'spatie/browsershot is not installed. Run `composer require --dev spatie/browsershot`.',
            );
        }

        try {
            /** @var class-string $browsershotClass */
            $browsershotClass = '\\Spatie\\Browsershot\\Browsershot';

            /** @var object $browsershot */
            $browsershot = $browsershotClass::html($html);
            $browsershot->paperSize(210, 297, 'mm');
            $browsershot->showBackground();

            $chromePath = (string) ($this->config['browsershot']['chrome_path'] ?? '');
            if ($chromePath !== '') {
                $browsershot->setChromePath($chromePath);
            }

            $timeout = (int) ($this->config['browsershot']['timeout'] ?? 60);
            if ($timeout > 0) {
                $browsershot->timeout($timeout);
            }

            /** @var mixed $output */
            $output = $browsershot->pdf();
        } catch (Throwable $exception) {
            throw new RenderException(
                sprintf('Browsershot PDF render failed: %s', $exception->getMessage()),
                previous: $exception,
            );
        }

        return is_string($output) ? $output : '';
    }

    private function renderWithDomPdf(string $html): string
    {
        if (! $this->capabilities->dompdfAvailable()) {
            throw new MissingRendererDependencyException(
                'dompdf/dompdf is not installed. Run `composer require --dev dompdf/dompdf`.',
            );
        }

        try {
            /** @var class-string $dompdfClass */
            $dompdfClass = '\\Dompdf\\Dompdf';

            /** @var object $dompdf */
            $dompdf = new $dompdfClass([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
            ]);

            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $output = $dompdf->output();
        } catch (Throwable $exception) {
            throw new RenderException(
                sprintf('DomPDF PDF render failed: %s', $exception->getMessage()),
                previous: $exception,
            );
        }

        return is_string($output) ? $output : '';
    }
}
