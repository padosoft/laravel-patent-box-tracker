<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use InvalidArgumentException;

/**
 * Single evidence record emitted by an EvidenceCollector.
 *
 * The `kind` discriminator tells downstream pipeline stages how to interpret
 * the payload; the `payload` map is collector-specific. Keeping the shape
 * loose here lets W4.B.2 (classifier) and W4.C (renderer) project the data
 * without forcing a rigid schema before the requirements settle.
 *
 * Allowed kinds:
 *   - `commit` — emitted by GitSourceCollector; one item per qualifying commit
 *   - `ai_attribution` — emitted by AiAttributionExtractor; one item per commit
 *   - `design_doc_link` — emitted by DesignDocCollector; one item per design doc
 *   - `branch_semantic` — emitted by BranchSemanticsCollector; one item per branch
 */
final class EvidenceItem
{
    public const KIND_COMMIT = 'commit';

    public const KIND_AI_ATTRIBUTION = 'ai_attribution';

    public const KIND_DESIGN_DOC_LINK = 'design_doc_link';

    public const KIND_BRANCH_SEMANTIC = 'branch_semantic';

    private const ALLOWED_KINDS = [
        self::KIND_COMMIT,
        self::KIND_AI_ATTRIBUTION,
        self::KIND_DESIGN_DOC_LINK,
        self::KIND_BRANCH_SEMANTIC,
    ];

    /**
     * @param  string  $kind  One of the KIND_* constants.
     * @param  string  $repositoryPath  Echoes CollectorContext::$repositoryPath for
     *                                  downstream cross-repo aggregation.
     * @param  string|null  $sha  Commit SHA (40-char hex). `null` for non-commit kinds
     *                            such as design doc or branch semantic.
     * @param  array<string, scalar|array<int|string, mixed>|null>  $payload
     *                                                                        Collector-specific payload — see each collector's `collect()` for the
     *                                                                        keys it emits. Kept as a generic associative array so the renderer
     *                                                                        can serialize without prior knowledge of every kind.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $repositoryPath,
        public readonly ?string $sha,
        public readonly array $payload,
    ) {
        $this->guardKind($kind);
        $this->guardRepositoryPath($repositoryPath);
        $this->guardSha($kind, $sha);
    }

    private function guardKind(string $kind): void
    {
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException(sprintf(
                'EvidenceItem: kind must be one of [%s], got "%s".',
                implode(', ', self::ALLOWED_KINDS),
                $kind,
            ));
        }
    }

    private function guardRepositoryPath(string $path): void
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException(
                'EvidenceItem: repositoryPath cannot be empty.'
            );
        }
    }

    private function guardSha(string $kind, ?string $sha): void
    {
        if ($kind === self::KIND_COMMIT || $kind === self::KIND_AI_ATTRIBUTION) {
            if ($sha === null || ! preg_match('/^[a-f0-9]{40}$/i', $sha)) {
                throw new InvalidArgumentException(sprintf(
                    'EvidenceItem: kind "%s" requires a 40-char hex SHA, got "%s".',
                    $kind,
                    $sha ?? '(null)',
                ));
            }
        }
    }
}
