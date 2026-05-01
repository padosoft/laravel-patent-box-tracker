<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use Padosoft\PatentBoxTracker\Models\TrackingSession;

/**
 * Pluggable dossier renderer interface — produces either a PDF
 * compliance artefact or a machine-readable JSON sidecar from a
 * persisted {@see TrackingSession}.
 *
 * Implementations MUST be deterministic given the same session
 * state: re-running the same renderer over the same session
 * produces byte-identical output. This is the load-bearing
 * invariant for the documentazione idonea regime — an Agenzia
 * delle Entrate auditor must be able to re-execute a dossier
 * generation and verify the SHA-256 head hash against the filed
 * dossier.
 *
 * Implementations MUST throw on every failure path. Returning an
 * empty string, `null`, or a 0-byte payload while signalling
 * "success" is forbidden (R14 — surface failures loudly): a 0-byte
 * PDF on disk while the call site logs "rendered ok" is exactly the
 * regression PR #25 / PR #27 shipped on AskMyDocs and we are not
 * repeating it here.
 */
interface DossierRenderer
{
    /**
     * Render the session into the renderer's native binary form.
     *
     * MUST throw on any failure — never return an empty body
     * silently. Wrap the underlying engine exception in
     * {@see RenderException} so call sites can catch a stable type.
     *
     * @throws RenderException when the renderer cannot produce output.
     */
    public function render(TrackingSession $session): RenderedDossier;

    /**
     * The format identifier this renderer produces.
     *
     * Returns one of `pdf` or `json` for v0.1; future locales (`xml`,
     * `xbrl`, …) may be added without changing the interface shape.
     */
    public function format(): string;
}
