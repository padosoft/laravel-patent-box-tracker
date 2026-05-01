<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Config;

/**
 * Per-repository entry inside a parsed cross-repo YAML config.
 *
 * Only two columns survive validation: the absolute filesystem path
 * to a git working tree (validated to exist + to be a git repo) and
 * the dossier role (`primary_ip` | `support` | `meta_self`). Other
 * per-repo knobs (excluded_authors, branch override) live in the
 * top-level config and are projected onto every CollectorContext.
 */
final readonly class RepoConfig
{
    public function __construct(
        public string $path,
        public string $role,
    ) {}
}
