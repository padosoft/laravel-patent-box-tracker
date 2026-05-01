<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Config;

use DateTimeImmutable;

/**
 * Typed result of a successful YAML config validation pass.
 *
 * Read-only — every consumer that holds a CrossRepoConfig can rely
 * on the invariants the validator already enforced (period bounds,
 * regime allowlist, primary_ip presence, every repo path is a real
 * git repo). Downstream code must never mutate the DTO.
 *
 * @phpstan-type TaxIdentity array{denomination: string, p_iva: string, regime: string}
 * @phpstan-type CostModel array{hourly_rate_eur: float, daily_hours_max: int}
 * @phpstan-type ClassifierConfig array{provider: string, model: string}
 * @phpstan-type ManualSupplement array<string, mixed>
 * @phpstan-type IpOutput array<string, mixed>
 */
final readonly class CrossRepoConfig
{
    /**
     * @param  TaxIdentity  $taxIdentity
     * @param  CostModel  $costModel
     * @param  ClassifierConfig  $classifier
     * @param  list<RepoConfig>  $repositories
     * @param  ManualSupplement  $manualSupplement
     * @param  list<IpOutput>  $ipOutputs
     */
    public function __construct(
        public string $fiscalYear,
        public DateTimeImmutable $periodFrom,
        public DateTimeImmutable $periodTo,
        public array $taxIdentity,
        public array $costModel,
        public array $classifier,
        public array $repositories,
        public array $manualSupplement = [],
        public array $ipOutputs = [],
    ) {}

    /**
     * Number of registered repositories. Surfaced for the
     * cross-repo runner's progress output.
     */
    public function repositoryCount(): int
    {
        return count($this->repositories);
    }

    /**
     * Filter repositories by role. Used by the dossier renderer to
     * separate primary IP repos from supporting ones in the
     * executive summary.
     *
     * @return list<RepoConfig>
     */
    public function repositoriesWithRole(string $role): array
    {
        return array_values(array_filter(
            $this->repositories,
            static fn (RepoConfig $repo): bool => $repo->role === $role,
        ));
    }
}
