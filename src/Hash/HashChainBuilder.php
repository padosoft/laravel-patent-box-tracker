<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Hash;

/**
 * Tamper-evidence hash chain builder for the dossier.
 *
 * Each commit's `self` hash is `sha256(prevHash . ':' . commitSha)`.
 * The colon separator stops trivial prefix-collision attacks: without
 * it, `(prev='abc', sha='def')` would concatenate to `'abcdef'`, which
 * collides with `(prev='abcde', sha='f')`. The colon is unambiguous —
 * it cannot appear inside a 64-char hex SHA, so the boundary between
 * the two inputs is always recoverable.
 *
 * The first commit in the chain uses `prev = null`, hashed as the
 * empty string (`sha256('' . ':' . sha)`). Patent Box auditors get a
 * deterministic head hash regardless of the chain length.
 *
 * Determinism is the load-bearing invariant: the same ordered SHA
 * stream MUST produce the same chain across runs, hosts, and
 * locales. Re-execution of the run by an Agenzia delle Entrate
 * auditor must reproduce the head hash byte-for-byte.
 */
final class HashChainBuilder
{
    /**
     * Compute the `self` hash for one chain link.
     *
     * @param  string|null  $prevHash  Previous link's `self`, or null for the first link.
     * @param  string  $commitSha  The 40-char hex commit SHA being chained.
     * @return string Lowercase 64-char hex SHA-256 digest.
     */
    public function link(?string $prevHash, string $commitSha): string
    {
        $prev = $prevHash ?? '';

        return hash('sha256', $prev.':'.$commitSha);
    }

    /**
     * Build the full hash chain for an ordered list of commit SHAs.
     *
     * @param  list<string>  $commits  Ordered list of commit SHAs (lowercase hex).
     * @return list<array{sha: string, prev: string|null, self: string}>
     */
    public function chain(array $commits): array
    {
        $manifest = [];
        $prev = null;
        foreach ($commits as $sha) {
            $self = $this->link($prev, $sha);
            $manifest[] = [
                'sha' => $sha,
                'prev' => $prev,
                'self' => $self,
            ];
            $prev = $self;
        }

        return $manifest;
    }

    /**
     * Re-compute the chain from the manifest's `sha` + `prev` columns
     * and return false on the first divergence between the manifest's
     * recorded `self` and the locally-computed value. Returns true
     * when the manifest is consistent.
     *
     * Returns a boolean (does NOT throw) so callers can format their
     * own forensic report — the renderer surfaces the offending row
     * index in the dossier appendix when verification fails.
     *
     * @param  list<array{sha: string, prev: string|null, self: string}>  $manifest
     */
    public function verify(array $manifest): bool
    {
        $expectedPrev = null;
        foreach ($manifest as $row) {
            if (! isset($row['sha'], $row['self']) || ! array_key_exists('prev', $row)) {
                return false;
            }
            if ($row['prev'] !== $expectedPrev) {
                return false;
            }
            $recomputed = $this->link($row['prev'], $row['sha']);
            if (! hash_equals($recomputed, $row['self'])) {
                return false;
            }
            $expectedPrev = $row['self'];
        }

        return true;
    }
}
