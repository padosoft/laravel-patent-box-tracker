<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use DateTimeImmutable;
use FilesystemIterator;
use Padosoft\PatentBoxTracker\Sources\Internal\GitProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Walks the repository's `docs/` and `plans/` folders for design-intent
 * markdown files and emits one `design_doc_link` EvidenceItem per match.
 *
 * Each item carries:
 *   - `path` — repo-relative path
 *   - `slug` — filename without `.md`
 *   - `title` — first H1 heading if present, else the slug
 *   - `firstSeenAt` — git log first commit that introduced the path
 *   - `lastModifiedAt` — most recent commit that touched the path
 *   - `correlatedCommits` — list of {sha, strength} where strength is one
 *     of `direct` (filename in changed-files), `proximate` (date proximity
 *     ± 14 days from a commit), `weak` (slug mention only).
 *
 * The correlation logic is intentionally simple — the LLM classifier in
 * W4.B.2 will refine the link strength using the actual commit context.
 */
final class DesignDocCollector implements EvidenceCollector
{
    private const PROXIMITY_DAYS = 14;

    /**
     * Path patterns considered design-intent docs. Each entry is a glob
     * relative to the repository root. The collector also accepts any
     * `*.md` file under `docs/` whose filename starts with one of the
     * known prefixes (PLAN-, ADR-, SPEC-, RFC-).
     */
    private const KNOWN_PREFIXES = ['PLAN-', 'ADR-', 'SPEC-', 'RFC-'];

    public function name(): string
    {
        return 'design-doc';
    }

    public function supports(CollectorContext $context): bool
    {
        if (! is_dir($context->repositoryPath)) {
            return false;
        }

        $whitelist = $context->evidencePaths !== []
            ? $context->evidencePaths
            : ['docs', 'plans'];

        $root = rtrim($context->repositoryPath, '/\\');
        foreach ($whitelist as $sub) {
            $absSub = $root.DIRECTORY_SEPARATOR.trim($sub, '/\\');
            if (is_dir($absSub)) {
                return true;
            }
        }

        return false;
    }

    public function collect(CollectorContext $context): iterable
    {
        if (! $this->supports($context)) {
            return;
        }

        $root = rtrim($context->repositoryPath, '/\\');
        $docPaths = $this->discoverDocPaths($root, $context);

        if ($docPaths === []) {
            return;
        }

        // Collect commit metadata once for correlation.
        $commits = $this->loadCommitsForCorrelation($root, $context);

        foreach ($docPaths as $relPath) {
            yield $this->buildItem($root, $relPath, $commits, $context);
        }
    }

    /**
     * @return list<string>
     */
    private function discoverDocPaths(string $root, CollectorContext $context): array
    {
        $whitelist = $context->evidencePaths !== []
            ? $context->evidencePaths
            : ['docs', 'plans'];

        $found = [];
        foreach ($whitelist as $sub) {
            $absSub = $root.DIRECTORY_SEPARATOR.trim($sub, '/\\');
            if (! is_dir($absSub)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absSub, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if (! $entry->isFile()) {
                    continue;
                }
                $ext = strtolower($entry->getExtension());
                if ($ext !== 'md' && $ext !== 'markdown') {
                    continue;
                }
                $rel = $this->makeRelative($entry->getPathname(), $root);
                if ($this->isDesignDoc($rel)) {
                    $found[] = $rel;
                }
            }
        }

        // Sort for determinism — the EvidenceItem stream is order-stable.
        sort($found);

        return $found;
    }

    private function isDesignDoc(string $relPath): bool
    {
        $base = basename($relPath);
        // PLAN-*, ADR-*, SPEC-*, RFC-* anywhere under the whitelisted folders.
        foreach (self::KNOWN_PREFIXES as $prefix) {
            if (str_starts_with($base, $prefix)) {
                return true;
            }
        }
        // Specific well-known names.
        if (preg_match('#(^|/)docs/v4-platform/PLAN-[^/]+\.md$#', $relPath)) {
            return true;
        }
        if (preg_match('#(^|/)docs/adr/[^/]+\.md$#', $relPath)) {
            return true;
        }
        if (preg_match('#(^|/)docs/superpowers/specs/[^/]+\.md$#', $relPath)) {
            return true;
        }
        if (preg_match('#(^|/)docs/plans/lessons-learned\.md$#', $relPath)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, array{sha:string,when:DateTimeImmutable,changedFiles:list<string>,message:string}>  $commits
     */
    private function buildItem(string $root, string $relPath, array $commits, CollectorContext $context): EvidenceItem
    {
        $absPath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        $title = $this->extractTitle($absPath) ?? pathinfo($relPath, PATHINFO_FILENAME);
        $slug = pathinfo($relPath, PATHINFO_FILENAME);

        [$firstSeen, $lastModified] = $this->resolveDocLifetime($root, $relPath);

        $correlated = $this->correlateCommits($relPath, $slug, $commits, $firstSeen);

        $payload = [
            'path' => $relPath,
            'slug' => $slug,
            'title' => $title,
            'firstSeenAt' => $firstSeen?->format("Y-m-d\TH:i:s\Z"),
            'lastModifiedAt' => $lastModified?->format("Y-m-d\TH:i:s\Z"),
            'correlatedCommits' => $correlated,
        ];

        return new EvidenceItem(
            kind: EvidenceItem::KIND_DESIGN_DOC_LINK,
            repositoryPath: $context->repositoryPath,
            sha: null,
            payload: $payload,
        );
    }

    private function extractTitle(string $absPath): ?string
    {
        if (! is_readable($absPath)) {
            return null;
        }
        $handle = @fopen($absPath, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $maxLines = 50;
            while ($maxLines-- > 0 && ! feof($handle)) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $trim = trim($line);
                if ($trim === '') {
                    continue;
                }
                if (str_starts_with($trim, '# ')) {
                    return trim(substr($trim, 2));
                }
                // Front-matter or first non-heading content — title isn't
                // there; bail out.
                if (! str_starts_with($trim, '---') && ! str_starts_with($trim, '#')) {
                    return null;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function resolveDocLifetime(string $root, string $relPath): array
    {
        if (! GitProcess::isRepository($root)) {
            return [null, null];
        }

        try {
            // First commit that touched this path (oldest).
            $firstOut = GitProcess::run($root, [
                'log', '--diff-filter=A', '--follow', '--reverse',
                '--pretty=format:%aI', '--', $relPath,
            ], 30);
            // Most recent commit that touched this path.
            $lastOut = GitProcess::run($root, [
                'log', '-1', '--pretty=format:%aI', '--', $relPath,
            ], 30);
        } catch (\RuntimeException) {
            return [null, null];
        }

        $firstLines = array_values(array_filter(
            preg_split('/\R/u', trim($firstOut)) ?: [],
            static fn (string $l): bool => $l !== '',
        ));
        $lastLines = array_values(array_filter(
            preg_split('/\R/u', trim($lastOut)) ?: [],
            static fn (string $l): bool => $l !== '',
        ));

        $first = $firstLines[0] ?? null;
        $last = $lastLines[0] ?? null;

        return [
            $first ? $this->safeDate($first) : null,
            $last ? $this->safeDate($last) : null,
        ];
    }

    private function safeDate(string $iso): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return list<array{sha:string,when:DateTimeImmutable,changedFiles:list<string>,message:string}>
     */
    private function loadCommitsForCorrelation(string $root, CollectorContext $context): array
    {
        if (! GitProcess::isRepository($root)) {
            return [];
        }

        $args = ['log'];
        if ($context->branch !== null && trim($context->branch) !== '') {
            $args[] = $context->branch;
        }
        $args[] = '--since='.$context->periodFrom->format(DATE_ATOM);
        $args[] = '--until='.$context->periodTo->format(DATE_ATOM);
        // Use a unit/record separator scheme so commit messages with
        // newlines do not corrupt parsing.
        $args[] = '--name-only';
        $args[] = '--pretty=format:'."\x1e".'%H'."\x1f".'%aI'."\x1f".'%B'."\x1f";

        try {
            $raw = GitProcess::run($root, $args, 120);
        } catch (\RuntimeException) {
            return [];
        }

        $records = array_values(array_filter(
            explode("\x1e", $raw),
            static fn (string $r): bool => trim($r) !== '',
        ));

        $commits = [];
        foreach ($records as $rec) {
            $parts = explode("\x1f", $rec, 4);
            if (count($parts) < 4) {
                continue;
            }
            $sha = strtolower(trim($parts[0]));
            $when = $this->safeDate(trim($parts[1]));
            $message = (string) $parts[2];
            $filesBlob = trim((string) $parts[3]);
            $files = array_values(array_filter(
                preg_split('/\R/u', $filesBlob) ?: [],
                static fn (string $l): bool => trim($l) !== '',
            ));
            if (! preg_match('/^[a-f0-9]{40}$/', $sha) || $when === null) {
                continue;
            }
            $commits[] = [
                'sha' => $sha,
                'when' => $when,
                'changedFiles' => $files,
                'message' => $message,
            ];
        }

        return $commits;
    }

    /**
     * @param  array<int, array{sha:string,when:DateTimeImmutable,changedFiles:list<string>,message:string}>  $commits
     * @return list<array{sha:string,strength:string}>
     */
    private function correlateCommits(string $relPath, string $slug, array $commits, ?DateTimeImmutable $firstSeen): array
    {
        $out = [];
        foreach ($commits as $c) {
            // Direct: filename appears in changed-files.
            if (in_array($relPath, $c['changedFiles'], true)) {
                $out[] = ['sha' => $c['sha'], 'strength' => 'direct'];

                continue;
            }
            // Proximate: date proximity to firstSeenAt.
            if ($firstSeen !== null) {
                $delta = abs($c['when']->getTimestamp() - $firstSeen->getTimestamp());
                if ($delta <= self::PROXIMITY_DAYS * 86400) {
                    // Weaker than direct but still a date-bound link.
                    if (str_contains($c['message'], $slug)) {
                        $out[] = ['sha' => $c['sha'], 'strength' => 'proximate'];

                        continue;
                    }
                }
            }
            // Weak: slug mention in message only (no date constraint).
            if (str_contains($c['message'], $slug)) {
                $out[] = ['sha' => $c['sha'], 'strength' => 'weak'];
            }
        }

        // Deterministic order: by SHA ascending.
        usort($out, static fn (array $a, array $b): int => strcmp($a['sha'], $b['sha']));

        return $out;
    }

    private function makeRelative(string $absPath, string $root): string
    {
        $absPath = str_replace('\\', '/', $absPath);
        $root = str_replace('\\', '/', $root);
        $root = rtrim($root, '/');
        if (str_starts_with($absPath, $root.'/')) {
            return substr($absPath, strlen($root) + 1);
        }

        return $absPath;
    }
}
