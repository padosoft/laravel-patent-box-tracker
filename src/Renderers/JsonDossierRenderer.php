<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

use JsonException;
use Padosoft\PatentBoxTracker\Models\TrackingSession;

/**
 * Canonical-JSON dossier renderer.
 *
 * Output rules (load-bearing for tamper-evidence):
 *   1. Keys at every depth are sorted lexicographically before
 *      `json_encode()`. Two runs over the same session MUST emit
 *      byte-identical bytes.
 *   2. Encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
 *      so paths and Italian commit messages round-trip without
 *      cosmetic backslash escaping.
 *   3. No pretty-printing: a single newline at end-of-file is the
 *      only whitespace.
 *   4. The `hash_chain.head` value is produced by the assembled
 *      manifest/commit hash-chain before serialisation; it is not
 *      recomputed from the final JSON bytes. Deterministic key
 *      ordering and encoding make the chain reproducible across
 *      runs, while the artefact-level integrity is covered by the
 *      `sha256` field on {@see RenderedDossier} computed from
 *      `$contents` after encoding.
 */
final class JsonDossierRenderer implements DossierRenderer
{
    public function __construct(
        private readonly DossierPayloadAssembler $assembler,
        private readonly string $locale = 'it',
    ) {}

    public function format(): string
    {
        return 'json';
    }

    public function render(TrackingSession $session): RenderedDossier
    {
        $payload = $this->assembler->assemble($session);

        $payload = $this->canonicalize($payload);

        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RenderException(
                sprintf(
                    'Failed to serialise dossier payload to canonical JSON: %s',
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        $contents = $body."\n";

        return new RenderedDossier(
            contents: $contents,
            sha256: hash('sha256', $contents),
            byteSize: strlen($contents),
            format: 'json',
            locale: $this->locale,
        );
    }

    /**
     * Recursively sort associative-array keys lexicographically while
     * preserving list ordering — list values keep their iteration
     * order (commits stay in committed_at order, manifest stays in
     * chain order). Without this discrimination the manifest would
     * be reordered alphabetically and the chain would no longer
     * verify.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = $this->canonicalize($item);
            }

            return $result;
        }

        ksort($value);
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = $this->canonicalize($v);
        }

        return $result;
    }

    /**
     * Cross-PHP-version `array_is_list()` — kept inline so the
     * renderer works on PHP 8.3 without a polyfill dependency.
     *
     * @param  array<int|string, mixed>  $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_is_list($value);
    }
}
