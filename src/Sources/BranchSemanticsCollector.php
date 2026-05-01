<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use Generator;
use Padosoft\PatentBoxTracker\Sources\Internal\GitProcess;

/**
 * Reads `git for-each-ref refs/heads` plus the historical refs visible in
 * `git log --all --format=%D` and emits one `branch_semantic` EvidenceItem
 * per UNIQUE branch name observed.
 *
 * Each item carries:
 *   - `branch` — canonical branch name (without `refs/heads/`)
 *   - `phase` — one of: development | enhancement | maintenance | hygiene |
 *               infrastructure | documentation | implementation | unknown
 *   - `qualified` — bool default flag; the LLM classifier may override
 *   - `prefix` — the matched prefix (`feature`, `fix`, `chore`, `ci`, `docs`)
 *   - `versionCycle` — `v4.0`, `v4.1`, etc. when the branch matches the
 *      versioned-cycle pattern; null otherwise
 *   - `subtask` — `W3.2`, `W4.B.1`, etc. when matched; null otherwise
 *   - `releaseStatus` — `pre_release` | `post_release` | `unknown`
 *
 * The classification table follows PLAN-W4 §3.4:
 *
 *   feature/v[N].[N]-W[N].[A]?- → development (versioned cycle), qualified
 *   feature/enh-                → enhancement, qualified (context-dependent)
 *   feature/                    → development, qualified
 *   fix/  (pre-release)         → implementation/validation, qualified
 *   fix/  (post-release)        → maintenance, NOT qualified
 *   chore/                      → hygiene, NOT qualified
 *   ci/                         → infrastructure, NOT qualified
 *   docs/                       → documentation, qualified (limited)
 */
final class BranchSemanticsCollector implements EvidenceCollector
{
    public const PHASE_DEVELOPMENT = 'development';

    public const PHASE_ENHANCEMENT = 'enhancement';

    public const PHASE_IMPLEMENTATION = 'implementation';

    public const PHASE_MAINTENANCE = 'maintenance';

    public const PHASE_HYGIENE = 'hygiene';

    public const PHASE_INFRASTRUCTURE = 'infrastructure';

    public const PHASE_DOCUMENTATION = 'documentation';

    public const PHASE_UNKNOWN = 'unknown';

    public const RELEASE_PRE = 'pre_release';

    public const RELEASE_POST = 'post_release';

    public const RELEASE_UNKNOWN = 'unknown';

    public function name(): string
    {
        return 'branch-semantics';
    }

    public function supports(CollectorContext $context): bool
    {
        return GitProcess::isRepository($context->repositoryPath);
    }

    /**
     * Like AiAttributionExtractor, this collector is a projection of the
     * git source — it walks `git for-each-ref` and `git log --all` rather
     * than commit history, but the source-of-evidence is the same git
     * repository. The overlap is by design: both contribute distinct
     * EvidenceItem kinds (`commit` vs `branch_semantic`).
     *
     * @return list<class-string<EvidenceCollector>>
     */
    public function overlapsBy(): array
    {
        return [GitSourceCollector::class, AiAttributionExtractor::class];
    }

    public function collect(CollectorContext $context): iterable
    {
        if (! $this->supports($context)) {
            return;
        }

        yield from $this->walk($context);
    }

    /**
     * @return Generator<int, EvidenceItem>
     */
    private function walk(CollectorContext $context): Generator
    {
        $branches = $this->discoverBranches($context->repositoryPath);

        foreach ($branches as $branch) {
            $semantics = self::classifyBranch($branch);
            $semantics['releaseStatus'] = $this->detectReleaseStatus(
                $context->repositoryPath,
                $branch,
            );

            // Refine phase + qualified for fix/* using release status.
            if ($semantics['prefix'] === 'fix') {
                if ($semantics['releaseStatus'] === self::RELEASE_POST) {
                    $semantics['phase'] = self::PHASE_MAINTENANCE;
                    $semantics['qualified'] = false;
                } elseif ($semantics['releaseStatus'] === self::RELEASE_PRE) {
                    $semantics['phase'] = self::PHASE_IMPLEMENTATION;
                    $semantics['qualified'] = true;
                }
                // unknown → leave whatever classifyBranch said.
            }

            $payload = [
                'branch' => $branch,
                'phase' => $semantics['phase'],
                'qualified' => $semantics['qualified'],
                'prefix' => $semantics['prefix'],
                'versionCycle' => $semantics['versionCycle'],
                'subtask' => $semantics['subtask'],
                'releaseStatus' => $semantics['releaseStatus'],
            ];

            yield new EvidenceItem(
                kind: EvidenceItem::KIND_BRANCH_SEMANTIC,
                repositoryPath: $context->repositoryPath,
                sha: null,
                payload: $payload,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function discoverBranches(string $cwd): array
    {
        $set = [];

        try {
            $heads = GitProcess::run($cwd, [
                'for-each-ref', '--format=%(refname:short)', 'refs/heads',
            ], 30);
            foreach (preg_split('/\R/u', trim($heads)) ?: [] as $line) {
                $name = trim($line);
                if ($name !== '') {
                    $set[$name] = true;
                }
            }
        } catch (\RuntimeException) {
            // No local branches — proceed.
        }

        try {
            $allRefs = GitProcess::run($cwd, [
                'log', '--all', '--format=%D',
            ], 60);
            foreach (preg_split('/\R/u', trim($allRefs)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                foreach (explode(',', $line) as $ref) {
                    $ref = trim($ref);
                    // Strip leading "HEAD -> " or "tag: " prefixes.
                    if (str_starts_with($ref, 'HEAD -> ')) {
                        $ref = substr($ref, 8);
                    }
                    if (str_starts_with($ref, 'tag:')) {
                        continue;
                    }
                    if ($ref === '' || $ref === 'HEAD') {
                        continue;
                    }
                    // Drop remote prefix (e.g. "origin/main") — we only want
                    // semantic branch names. Strip everything up to the
                    // first slash IF the first segment is a known remote.
                    if (preg_match('#^(origin|upstream|fork)/(.+)$#', $ref, $m)) {
                        $ref = $m[2];
                    }
                    $set[$ref] = true;
                }
            }
        } catch (\RuntimeException) {
            // No history — proceed with whatever we have.
        }

        $branches = array_keys($set);
        sort($branches);

        return $branches;
    }

    /**
     * Classify a branch name using prefix + structural patterns. Public +
     * static so tests can assert directly without a git fixture.
     *
     * @return array{phase:string,qualified:bool,prefix:string,versionCycle:?string,subtask:?string}
     */
    public static function classifyBranch(string $branch): array
    {
        // Versioned-cycle development branches:
        //   feature/v4.0-W3.2-vercel-chat-migration
        //   feature/v4.0/W4.B.1/collectors
        if (preg_match(
            '#^feature/v(?<ver>\d+\.\d+)[/\-]W(?<sub>\d+(?:\.[A-Z0-9]+)+)#i',
            $branch,
            $m,
        )) {
            return [
                'phase' => self::PHASE_DEVELOPMENT,
                'qualified' => true,
                'prefix' => 'feature',
                'versionCycle' => 'v'.$m['ver'],
                'subtask' => 'W'.$m['sub'],
            ];
        }

        // Plain-versioned development branch (no sub-task identifier):
        //   feature/v4.0
        //   feature/v4.0-experimental
        if (preg_match('#^feature/v(?<ver>\d+\.\d+)#', $branch, $m)) {
            return [
                'phase' => self::PHASE_DEVELOPMENT,
                'qualified' => true,
                'prefix' => 'feature',
                'versionCycle' => 'v'.$m['ver'],
                'subtask' => null,
            ];
        }

        // Sub-task identifier without versioned prefix:
        //   feature/W3.2-some-thing
        if (preg_match('#^feature/W(?<sub>\d+(?:\.[A-Z0-9]+)+)#i', $branch, $m)) {
            return [
                'phase' => self::PHASE_DEVELOPMENT,
                'qualified' => true,
                'prefix' => 'feature',
                'versionCycle' => null,
                'subtask' => 'W'.$m['sub'],
            ];
        }

        if (str_starts_with($branch, 'feature/enh-') || str_starts_with($branch, 'feature/enh/')) {
            return [
                'phase' => self::PHASE_ENHANCEMENT,
                'qualified' => true,
                'prefix' => 'feature',
                'versionCycle' => null,
                'subtask' => null,
            ];
        }

        if (str_starts_with($branch, 'feature/')) {
            return [
                'phase' => self::PHASE_DEVELOPMENT,
                'qualified' => true,
                'prefix' => 'feature',
                'versionCycle' => null,
                'subtask' => null,
            ];
        }

        if (str_starts_with($branch, 'fix/')) {
            // Phase + qualified are refined by release-status detection
            // inside walk(); the default here is "unknown release status"
            // → implementation/qualified, and the caller will override
            // when post-release evidence is found.
            return [
                'phase' => self::PHASE_IMPLEMENTATION,
                'qualified' => true,
                'prefix' => 'fix',
                'versionCycle' => null,
                'subtask' => null,
            ];
        }

        if (str_starts_with($branch, 'chore/')) {
            return [
                'phase' => self::PHASE_HYGIENE,
                'qualified' => false,
                'prefix' => 'chore',
                'versionCycle' => null,
                'subtask' => null,
            ];
        }

        if (str_starts_with($branch, 'ci/')) {
            return [
                'phase' => self::PHASE_INFRASTRUCTURE,
                'qualified' => false,
                'prefix' => 'ci',
                'versionCycle' => null,
                'subtask' => null,
            ];
        }

        if (str_starts_with($branch, 'docs/')) {
            return [
                'phase' => self::PHASE_DOCUMENTATION,
                'qualified' => true,
                'prefix' => 'docs',
                'versionCycle' => null,
                'subtask' => null,
            ];
        }

        return [
            'phase' => self::PHASE_UNKNOWN,
            'qualified' => false,
            'prefix' => '',
            'versionCycle' => null,
            'subtask' => null,
        ];
    }

    private function detectReleaseStatus(string $cwd, string $branch): string
    {
        // We approximate release status by checking whether the tip of the
        // branch (or its merge-base with HEAD) descends from any tag matching
        // `vN.N.N`. If `git describe --tags` returns a tag, we treat it as
        // post-release (a version was already cut from this lineage). Else
        // the branch is pre-release.
        try {
            $exists = GitProcess::run($cwd, ['rev-parse', '--verify', '--quiet', $branch], 5);
            if (trim($exists) === '') {
                return self::RELEASE_UNKNOWN;
            }
        } catch (\RuntimeException) {
            return self::RELEASE_UNKNOWN;
        }

        try {
            $describe = GitProcess::run($cwd, [
                'describe', '--tags', '--abbrev=0', '--match', 'v[0-9]*.[0-9]*.[0-9]*', $branch,
            ], 10);
            $tag = trim($describe);
            if ($tag === '') {
                return self::RELEASE_PRE;
            }

            return self::RELEASE_POST;
        } catch (\RuntimeException) {
            // No matching tag in the lineage.
            return self::RELEASE_PRE;
        }
    }
}
