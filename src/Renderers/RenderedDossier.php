<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Renderers;

/**
 * Readonly DTO returned by every {@see DossierRenderer} implementation.
 *
 * Carries the rendered binary blob plus the audit-trail metadata
 * (SHA-256 of the bytes, byte size, format identifier, locale) so
 * callers can persist the artefact and verify its integrity later
 * without re-running the renderer.
 *
 * The class is `final readonly` (PHP 8.2+) so the binary contents
 * cannot be mutated after the renderer signs the SHA-256.
 */
final readonly class RenderedDossier
{
    /**
     * @param  string  $contents  Raw binary payload — PDF bytes (`%PDF-…`) or canonical-JSON UTF-8 string.
     * @param  string  $sha256  Lowercase 64-char hex SHA-256 of {@see $contents}.
     * @param  int  $byteSize  Length of {@see $contents} in bytes.
     * @param  string  $format  Format identifier — `pdf` or `json`.
     * @param  string  $locale  Locale identifier — `it` for v0.1.
     */
    public function __construct(
        public string $contents,
        public string $sha256,
        public int $byteSize,
        public string $format,
        public string $locale,
    ) {}

    /**
     * Persist the rendered bytes to {@see $path} on the local filesystem.
     *
     * Returns `true` on success. Throws {@see RenderException} when
     * `file_put_contents()` returns `false` (R4 — never ignore the
     * return value of a side-effecting call) so callers cannot silently
     * lose a dossier on disk-full / permission errors.
     *
     * @throws RenderException when the write fails.
     */
    public function save(string $path): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RenderException(sprintf(
                'Unable to create dossier output directory "%s".',
                $directory,
            ));
        }

        $written = file_put_contents($path, $this->contents);
        if ($written === false) {
            throw new RenderException(sprintf(
                'Unable to persist dossier to "%s" (file_put_contents returned false).',
                $path,
            ));
        }

        if ($written !== $this->byteSize) {
            throw new RenderException(sprintf(
                'Short write to "%s": expected %d bytes, wrote %d.',
                $path,
                $this->byteSize,
                $written,
            ));
        }

        return true;
    }
}
