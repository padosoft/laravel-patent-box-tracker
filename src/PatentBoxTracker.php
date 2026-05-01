<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker;

use RuntimeException;

/**
 * Public fluent-builder entry point for the package.
 *
 * The full API (`for(...)`, `coveringPeriod(...)`, `classifiedBy(...)`,
 * `withTaxIdentity(...)`, `withCostModel(...)`, `run()`) is implemented
 * across W4.B (collectors + classifier), W4.C (renderers + hash-chain),
 * and W4.D (cross-repo orchestration + dogfooding). The scaffold lands
 * the class so README quick-start examples resolve and IDE autocomplete
 * works against the published API surface; every entrypoint throws a
 * RuntimeException until the corresponding sub-task ships.
 */
final class PatentBoxTracker
{
    /**
     * Begin a tracking session for one or more repositories.
     *
     * @param  string|array<int, string>  $repositories  Local filesystem path(s) to the git repos to track.
     */
    public static function for(string|array $repositories): self
    {
        $_ = $repositories;

        throw new RuntimeException(
            'PatentBoxTracker::for() is not implemented in the W4.A scaffold. '
            .'The collector + classifier pipeline lands in W4.B; the renderer in W4.C; '
            .'the cross-repo orchestrator in W4.D. See PLAN-W4 §8 sub-task breakdown.'
        );
    }
}
