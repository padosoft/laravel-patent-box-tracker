<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use Generator;

/**
 * Walks the same git history GitSourceCollector walks but emits one
 * `ai_attribution` EvidenceItem per commit, classifying authorship as
 * `human` / `ai_assisted` / `ai_authored` / `mixed` based on:
 *
 *   - `Co-Authored-By: Claude ... <... @anthropic.com>` trailers
 *   - `Co-Authored-By: GitHub Copilot ... <bot@github.com>` trailers
 *   - Inline `AI-Tool: <name>` / `AI: <name>` trailers
 *
 *   - Author/committer email matching `noreply@anthropic.com` or `*@github.com`
 *
 * Confidence:
 *   - 1.0 — explicit Co-Authored-By trailer with a known AI signature
 *   - 0.7 — email-pattern match only (no trailer)
 *   - 0.5 — ambiguous / inferred mixed (one trailer + a different signal)
 *
 * The output marker list contains the substrings that triggered the
 * classification, audited downstream when the renderer surfaces "why this
 * commit was flagged AI-assisted" in the dossier.
 *
 * NOTE: this collector reuses GitSourceCollector to produce the underlying
 * commit stream. Composing rather than re-walking keeps the two collectors
 * single-source-of-truth on git invocation and ensures bot filtering /
 * merge-commit filtering stays consistent.
 */
final class AiAttributionExtractor implements EvidenceCollector
{
    public const ATTRIBUTION_HUMAN = 'human';

    public const ATTRIBUTION_AI_ASSISTED = 'ai_assisted';

    public const ATTRIBUTION_AI_AUTHORED = 'ai_authored';

    public const ATTRIBUTION_MIXED = 'mixed';

    public function __construct(
        private readonly GitSourceCollector $gitSource = new GitSourceCollector,
    ) {}

    public function name(): string
    {
        return 'ai-attribution';
    }

    public function supports(CollectorContext $context): bool
    {
        // This collector OVERLAPS with GitSourceCollector by design — both
        // walk the same source. The CollectorRegistry mutex SKIPS this
        // collector's overlap with GitSourceCollector via the
        // `overlapsBy` mechanism. See CollectorRegistry::class for the
        // exact rule.
        return $this->gitSource->supports($context);
    }

    /**
     * Declare the collectors this extractor intentionally overlaps with —
     * the registry uses this list to skip the non-overlap mutex check
     * between THIS collector and any of the listed FQCNs. The overlap
     * is by design: the AiAttributionExtractor is a *projection* of the
     * GitSourceCollector stream, not a competing collector.
     *
     * BranchSemanticsCollector is also part of the "git-family" — all
     * three collectors derive from `git log` / `git for-each-ref` and
     * emit distinct `EvidenceItem::KIND_*` kinds. The kind discriminator
     * keeps the streams separable downstream.
     *
     * @return list<class-string<EvidenceCollector>>
     */
    public function overlapsBy(): array
    {
        return [GitSourceCollector::class, BranchSemanticsCollector::class];
    }

    public function collect(CollectorContext $context): iterable
    {
        if (! $this->supports($context)) {
            return;
        }

        yield from $this->extractFromGitStream($context);
    }

    /**
     * @return Generator<int, EvidenceItem>
     */
    private function extractFromGitStream(CollectorContext $context): Generator
    {
        foreach ($this->gitSource->collect($context) as $commitItem) {
            if ($commitItem->kind !== EvidenceItem::KIND_COMMIT) {
                continue;
            }

            /** @var string $sha */
            $sha = $commitItem->sha ?? '';
            $payload = $commitItem->payload;

            $message = (string) ($payload['message'] ?? '');
            $authorEmail = (string) ($payload['authorEmail'] ?? '');
            $committerEmail = (string) ($payload['committerEmail'] ?? '');

            $classification = self::classify($message, $authorEmail, $committerEmail);

            yield new EvidenceItem(
                kind: EvidenceItem::KIND_AI_ATTRIBUTION,
                repositoryPath: $context->repositoryPath,
                sha: $sha,
                payload: [
                    'attribution' => $classification['attribution'],
                    'confidence' => $classification['confidence'],
                    'markers' => $classification['markers'],
                ],
            );
        }
    }

    /**
     * Classify a single commit message + author/committer emails. Public +
     * static so tests can drive the classifier directly without a git
     * fixture.
     *
     * @return array{attribution:string,confidence:float,markers:list<string>}
     */
    public static function classify(string $message, string $authorEmail, string $committerEmail): array
    {
        $markers = [];
        $hasAiTrailer = false;
        $hasMultipleAiTrailers = false;
        $aiTrailerCount = 0;

        // 1. Co-Authored-By trailers — case-insensitive multiline match.
        if (preg_match_all(
            '/^Co-Authored-By:\s*(?<name>[^<\r\n]+?)\s*<(?<email>[^>\r\n]+)>\s*$/im',
            $message,
            $coAuthMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($coAuthMatches as $match) {
                $name = strtolower(trim($match['name']));
                $email = strtolower(trim($match['email']));
                if ($email === 'noreply@anthropic.com' || str_ends_with($email, '@anthropic.com')) {
                    $hasAiTrailer = true;
                    $aiTrailerCount++;
                    $markers[] = 'co-authored-by:'.$email;

                    continue;
                }
                if ($email === 'bot@github.com' || str_contains($name, 'copilot')) {
                    $hasAiTrailer = true;
                    $aiTrailerCount++;
                    $markers[] = 'co-authored-by:'.$email;

                    continue;
                }
                if (str_contains($name, 'claude')
                    || str_contains($name, 'gpt')
                    || str_contains($name, 'gemini')) {
                    $hasAiTrailer = true;
                    $aiTrailerCount++;
                    $markers[] = 'co-authored-by:'.$name;
                }
            }
        }

        if ($aiTrailerCount > 1) {
            $hasMultipleAiTrailers = true;
        }

        // 2. Inline AI-Tool: / AI: trailers.
        if (preg_match('/^AI-Tool:\s*(?<tool>\S.*)$/im', $message, $m)) {
            $hasAiTrailer = true;
            $markers[] = 'ai-tool:'.strtolower(trim($m['tool']));
            $aiTrailerCount++;
        }
        if (preg_match('/^AI:\s*(?<tool>\S.*)$/im', $message, $m)) {
            $hasAiTrailer = true;
            $markers[] = 'ai:'.strtolower(trim($m['tool']));
            $aiTrailerCount++;
        }

        // 3. Author / committer email pattern match (signal without trailer).
        $authorIsBot = self::emailIsAi($authorEmail);
        $committerIsBot = self::emailIsAi($committerEmail);
        if ($authorIsBot) {
            $markers[] = 'author-email:'.strtolower($authorEmail);
        }
        if ($committerIsBot) {
            $markers[] = 'committer-email:'.strtolower($committerEmail);
        }

        // Decision tree.
        // Pure human: no trailer, no bot email.
        if (! $hasAiTrailer && ! $authorIsBot && ! $committerIsBot) {
            return [
                'attribution' => self::ATTRIBUTION_HUMAN,
                'confidence' => 1.0,
                'markers' => [],
            ];
        }

        // AI-authored: author email is bot (commit was COMMITTED by an AI bot
        // account, not just co-authored). Confidence 1.0 when reinforced by
        // a trailer; 0.7 when by email signal alone.
        if ($authorIsBot) {
            $confidence = $hasAiTrailer ? 1.0 : 0.7;

            return [
                'attribution' => self::ATTRIBUTION_AI_AUTHORED,
                'confidence' => $confidence,
                'markers' => array_values(array_unique($markers)),
            ];
        }

        // Mixed: multiple AI trailers (e.g., Claude + Copilot together) OR
        // an AI trailer + a non-bot human author + bot committer. The
        // `! $authorIsBot` clause is implied here because the previous
        // branch already returned for that case.
        if ($hasMultipleAiTrailers
            || ($hasAiTrailer && $committerIsBot)) {
            return [
                'attribution' => self::ATTRIBUTION_MIXED,
                'confidence' => 0.5,
                'markers' => array_values(array_unique($markers)),
            ];
        }

        // AI-assisted: human author + an AI trailer (the canonical Claude /
        // Copilot Co-Authored-By case).
        if ($hasAiTrailer) {
            return [
                'attribution' => self::ATTRIBUTION_AI_ASSISTED,
                'confidence' => 1.0,
                'markers' => array_values(array_unique($markers)),
            ];
        }

        // Fallback: committer email looks like a bot but no trailer. Signal-
        // only inference, low confidence.
        return [
            'attribution' => self::ATTRIBUTION_AI_ASSISTED,
            'confidence' => 0.7,
            'markers' => array_values(array_unique($markers)),
        ];
    }

    private static function emailIsAi(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        if ($email === 'noreply@anthropic.com') {
            return true;
        }
        if (str_ends_with($email, '@anthropic.com')) {
            return true;
        }
        // GitHub Copilot uses bot@github.com; some flows leak <bot-id>@users.noreply.github.com
        // for genuine humans, so we ONLY match the exact bot address to
        // avoid false positives.
        if ($email === 'bot@github.com') {
            return true;
        }

        return false;
    }
}
