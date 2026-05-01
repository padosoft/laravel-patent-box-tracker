<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable input bundle for an EvidenceCollector run.
 *
 * The context fully describes the slice of source-of-evidence the collector
 * should walk: which repository, which role it plays in the dossier, the
 * branch filter (if any), and the inclusive period that bounds the walk.
 *
 * Excluded-author substrings are matched against author email AND committer
 * email; any commit whose either email contains one of these substrings is
 * skipped at the collector layer (bot filtering).
 */
final class CollectorContext
{
    /**
     * @param  string  $repositoryPath  Absolute path to a working tree or bare git repository.
     * @param  string  $repositoryRole  One of `primary_ip`, `support`, or `meta_self`.
     *                                  Surfaces in dossier metadata so audit reviewers can
     *                                  distinguish "the IP repo" from supporting infrastructure.
     * @param  string|null  $branch  Optional branch filter. `null` means the collector decides
     *                               (e.g. `--all` for full history vs `HEAD` for current branch).
     * @param  DateTimeImmutable  $periodFrom  Inclusive lower bound (UTC).
     * @param  DateTimeImmutable  $periodTo  Inclusive upper bound (UTC).
     * @param  list<string>  $excludedAuthors  Substring patterns matched against author/committer
     *                                         email; matching commits are skipped.
     * @param  list<string>  $evidencePaths  Optional whitelist of repo-relative directories to
     *                                       walk for design-doc evidence. Empty means the
     *                                       collector default (`docs/`, `plans/`).
     */
    public function __construct(
        public readonly string $repositoryPath,
        public readonly string $repositoryRole,
        public readonly ?string $branch,
        public readonly DateTimeImmutable $periodFrom,
        public readonly DateTimeImmutable $periodTo,
        public readonly array $excludedAuthors = [],
        public readonly array $evidencePaths = [],
    ) {
        $this->guardRole($repositoryRole);
        $this->guardPeriod($periodFrom, $periodTo);
        $this->guardRepositoryPath($repositoryPath);
    }

    /**
     * Returns true when the given author/committer email should be excluded
     * from the collector output (bot filter).
     */
    public function authorIsExcluded(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        foreach ($this->excludedAuthors as $needle) {
            $needle = strtolower(trim($needle));
            if ($needle === '') {
                continue;
            }
            if (str_contains($email, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function guardRole(string $role): void
    {
        $allowed = ['primary_ip', 'support', 'meta_self'];
        if (! in_array($role, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'CollectorContext: repositoryRole must be one of [%s], got "%s".',
                implode(', ', $allowed),
                $role,
            ));
        }
    }

    private function guardPeriod(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        if ($from > $to) {
            throw new InvalidArgumentException(sprintf(
                'CollectorContext: periodFrom (%s) must be <= periodTo (%s).',
                $from->format(DATE_ATOM),
                $to->format(DATE_ATOM),
            ));
        }
    }

    private function guardRepositoryPath(string $path): void
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException(
                'CollectorContext: repositoryPath cannot be empty.'
            );
        }
    }
}
