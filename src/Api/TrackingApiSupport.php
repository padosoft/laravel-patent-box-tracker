<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Api;

use DateTimeImmutable;
use Padosoft\PatentBoxTracker\Classifier\CostCapGuard;
use Padosoft\PatentBoxTracker\Sources\Internal\GitProcess;

final class TrackingApiSupport
{
    public function __construct(private readonly CostCapGuard $costCapGuard) {}

    public function assertRepository(string $path): void
    {
        if (! is_dir($path) || ! GitProcess::isRepository($path)) {
            throw new \InvalidArgumentException(sprintf(
                'Repository path "%s" does not exist or is not a git repository.',
                $path,
            ));
        }
    }

    public function commitCountForWindow(
        string $path,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $excludedAuthors = []
    ): int {
        $this->assertRepository($path);

        $stdout = GitProcess::run($path, [
            'log',
            '--all',
            '--since='.$from->format('Y-m-d\TH:i:s\Z'),
            '--until='.$to->format('Y-m-d\TH:i:s\Z'),
            '--format=%ae',
        ]);

        $emails = array_filter(array_map('trim', preg_split('/\R/', $stdout) ?: []));
        if ($emails === []) {
            return 0;
        }

        $count = 0;
        foreach ($emails as $email) {
            if (! is_string($email) || $email === '') {
                continue;
            }
            if ($this->isExcludedAuthor($email, $excludedAuthors)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function projectedCost(int $commitCount, string $model): ?float
    {
        return $this->costCapGuard->project($commitCount, $model);
    }

    /**
     * @param  list<string>  $excludedAuthors
     */
    private function isExcludedAuthor(string $email, array $excludedAuthors): bool
    {
        $emailLower = strtolower($email);
        foreach ($excludedAuthors as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (str_contains($emailLower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}

