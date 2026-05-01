<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Padosoft\PatentBoxTracker\Sources\Internal\GitProcess;

/**
 * Walks `git log` with `--first-parent` and emits one EvidenceItem per
 * qualifying commit. Filters merge commits and bot authors per the
 * CollectorContext::$excludedAuthors substring list.
 *
 * Per-commit hash chain `H(prev_hash || sha)` is computed during the walk
 * and stored under `payload.hashChainSelf`; the renderer uses it to render
 * the tamper-evident manifest in the dossier appendix (W4.C).
 *
 * Determinism: walks first-parent in commit order; with the same git state
 * the SHA stream is byte-stable, so the hash chain is reproducible.
 */
final class GitSourceCollector implements EvidenceCollector
{
    /**
     * Sentinel used as the "previous hash" for the first commit emitted.
     * 32 zero bytes encoded as 64-char hex so the chain has a consistent
     * shape for every commit including the first.
     */
    private const HASH_CHAIN_SEED = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Field separator used inside the `git log --pretty=format` template.
     * `\x1f` (Unit Separator) is unlikely to appear inside any field of a
     * normal commit and survives unicode commit messages cleanly.
     */
    private const FIELD_SEP = "\x1f";

    /**
     * Record separator between commits in the `git log` output. `\x1e`
     * (Record Separator) is the natural pair to FIELD_SEP and avoids
     * collisions with newlines inside commit messages.
     */
    private const RECORD_SEP = "\x1e";

    public function name(): string
    {
        return 'git-source';
    }

    public function supports(CollectorContext $context): bool
    {
        return GitProcess::isRepository($context->repositoryPath);
    }

    /**
     * The "git-family" cluster — GitSourceCollector is the canonical commit
     * stream; AiAttributionExtractor and BranchSemanticsCollector are
     * projections of it. The registry treats the overlap as a contract:
     * each collector emits a DISTINCT EvidenceItem kind so the union is
     * unambiguous.
     *
     * @return list<class-string<EvidenceCollector>>
     */
    public function overlapsBy(): array
    {
        return [AiAttributionExtractor::class, BranchSemanticsCollector::class];
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
        $args = ['log', '--first-parent'];

        if ($context->branch !== null && trim($context->branch) !== '') {
            $args[] = $context->branch;
        }

        // ISO 8601 boundaries; git accepts them through --since / --until.
        $args[] = '--since='.$context->periodFrom->format(DATE_ATOM);
        $args[] = '--until='.$context->periodTo->format(DATE_ATOM);

        // Always reverse so the chain seed seeds the OLDEST commit and
        // grows forward in time — auditors expect chronological order.
        $args[] = '--reverse';

        // Custom format. Numstat + a name-status block per commit lets us
        // count files/insertions/deletions without a second log pass.
        $format = implode(self::FIELD_SEP, [
            '%H',         // 0 SHA
            '%P',         // 1 parent SHAs (space separated)
            '%an',        // 2 author name
            '%ae',        // 3 author email
            '%ce',        // 4 committer email
            '%aI',        // 5 author date (ISO 8601 strict)
            '%s',         // 6 subject
            '%b',         // 7 body
        ]);

        $args[] = '--pretty=format:'.self::RECORD_SEP.$format.self::FIELD_SEP;
        $args[] = '--numstat';

        $output = GitProcess::run($context->repositoryPath, $args, 120);

        $prevHash = self::HASH_CHAIN_SEED;

        foreach ($this->splitRecords($output) as $rawRecord) {
            $commit = $this->parseRecord($rawRecord);
            if ($commit === null) {
                continue;
            }

            // Skip merge commits — more than one parent.
            if (count($commit['parents']) > 1) {
                continue;
            }

            // Bot filter — author OR committer email substring match.
            if ($context->authorIsExcluded($commit['authorEmail'])
                || $context->authorIsExcluded($commit['committerEmail'])) {
                continue;
            }

            $hashChainSelf = hash('sha256', $prevHash.$commit['sha']);

            $payload = [
                'sha' => $commit['sha'],
                'parents' => $commit['parents'],
                'authorName' => $commit['authorName'],
                'authorEmail' => $commit['authorEmail'],
                'committerEmail' => $commit['committerEmail'],
                'committedAt' => $commit['committedAt'],
                'subject' => $commit['subject'],
                'body' => $commit['body'],
                'message' => $commit['message'],
                'filesChanged' => $commit['filesChanged'],
                'insertions' => $commit['insertions'],
                'deletions' => $commit['deletions'],
                'hashChainPrev' => $prevHash,
                'hashChainSelf' => $hashChainSelf,
            ];

            yield new EvidenceItem(
                kind: EvidenceItem::KIND_COMMIT,
                repositoryPath: $context->repositoryPath,
                sha: $commit['sha'],
                payload: $payload,
            );

            $prevHash = $hashChainSelf;
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function splitRecords(string $raw): Generator
    {
        if ($raw === '') {
            return;
        }

        // Each commit starts with RECORD_SEP because of our --pretty=format.
        $records = explode(self::RECORD_SEP, $raw);
        foreach ($records as $record) {
            $record = trim($record, "\n\r");
            if ($record === '') {
                continue;
            }
            yield $record;
        }
    }

    /**
     * @return array{sha:string,parents:list<string>,authorName:string,authorEmail:string,committerEmail:string,committedAt:string,subject:string,body:string,message:string,filesChanged:list<array{path:string,insertions:int,deletions:int}>,insertions:int,deletions:int}|null
     */
    private function parseRecord(string $record): ?array
    {
        // The record is FIELD_SEP-separated; trailing FIELD_SEP in our
        // --pretty=format template guarantees a slot for the numstat block.
        $parts = explode(self::FIELD_SEP, $record);
        if (count($parts) < 9) {
            return null;
        }

        $sha = trim($parts[0]);
        if (! preg_match('/^[a-f0-9]{40}$/i', $sha)) {
            return null;
        }

        $parents = array_values(array_filter(
            preg_split('/\s+/', trim($parts[1])) ?: [],
            static fn (string $p): bool => $p !== '',
        ));
        $authorName = trim($parts[2]);
        $authorEmail = trim($parts[3]);
        $committerEmail = trim($parts[4]);
        $committedAt = $this->normalizeIsoDate(trim($parts[5]));
        $subject = trim($parts[6]);
        // parts[7] is the commit body. parts[8..] holds the numstat block
        // (one line per changed file: "<ins>\t<del>\t<path>"). The trailing
        // FIELD_SEP in our --pretty=format template guarantees parts[8]
        // exists; if there are no changed files at all (empty commit), the
        // numstat block is empty.
        $body = $parts[7];
        $numstatBlock = $parts[8];

        $files = $this->parseNumstat($numstatBlock);
        $insertions = array_sum(array_map(static fn (array $f): int => $f['insertions'], $files));
        $deletions = array_sum(array_map(static fn (array $f): int => $f['deletions'], $files));

        $message = trim($subject."\n\n".$body);

        return [
            'sha' => strtolower($sha),
            'parents' => array_map(static fn (string $p): string => strtolower($p), $parents),
            'authorName' => $authorName,
            'authorEmail' => $authorEmail,
            'committerEmail' => $committerEmail,
            'committedAt' => $committedAt,
            'subject' => $subject,
            'body' => trim($body),
            'message' => $message,
            'filesChanged' => $files,
            'insertions' => $insertions,
            'deletions' => $deletions,
        ];
    }

    /**
     * @return list<array{path:string,insertions:int,deletions:int}>
     */
    private function parseNumstat(string $block): array
    {
        $files = [];
        $lines = preg_split('/\R/u', $block) ?: [];
        foreach ($lines as $line) {
            if (! preg_match('/^(\S+)\t(\S+)\t(.+)$/', $line, $m)) {
                continue;
            }
            $ins = $m[1] === '-' ? 0 : (int) $m[1];
            $del = $m[2] === '-' ? 0 : (int) $m[2];
            $path = $m[3];
            $files[] = [
                'path' => $path,
                'insertions' => $ins,
                'deletions' => $del,
            ];
        }

        return $files;
    }

    private function normalizeIsoDate(string $iso): string
    {
        try {
            $dt = new DateTimeImmutable($iso);
            $utc = $dt->setTimezone(new DateTimeZone('UTC'));

            return $utc->format("Y-m-d\TH:i:s\Z");
        } catch (\Exception) {
            return $iso;
        }
    }
}
