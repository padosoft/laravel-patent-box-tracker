<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

/**
 * Pluggable evidence collector — produces EvidenceItem records by walking a
 * single source-of-truth (git history, design docs, branch refs, etc.).
 *
 * Implementations MUST be deterministic: re-running `collect()` against the
 * same repository state and the same context MUST produce the same item
 * stream in the same order. Patent Box auditors rely on this invariant when
 * re-executing a dossier run to verify byte-for-byte reproducibility.
 *
 * Implementations MUST also be standalone-agnostic: zero references to
 * upstream consumer symbols, zero queries against consumer-specific
 * domain tables, zero hard dependency on any consumer package. The
 * architecture test `tests/Architecture/StandaloneAgnosticTest.php`
 * enforces this invariant by grepping for forbidden substrings.
 *
 * Pluggable-pipeline contract per R23 (boot-time FQCN validation +
 * non-overlap mutex on `supports()`):
 *   1. Every registered FQCN MUST implement this interface — the registry
 *      asserts `is_subclass_of(<fqcn>, EvidenceCollector::class)` at boot
 *      and throws InvalidArgumentException if not.
 *   2. For any single CollectorContext, AT MOST ONE registered collector
 *      MAY return true from `supports()`. The registry tests every pair
 *      against a fixture context and throws on overlap.
 *
 * @see CollectorRegistry
 */
interface EvidenceCollector
{
    /**
     * Whether this collector applies to the given session context.
     *
     * MUST be deterministic — repeated calls with the same context return
     * the same boolean. Two registered collectors MUST NOT both return true
     * for the same context (the registry enforces a non-overlap mutex).
     */
    public function supports(CollectorContext $context): bool;

    /**
     * Walk the source and emit evidence items.
     *
     * Returns a generator (or any iterable) so callers can stream-process
     * for memory safety on large repositories — see R3 (memory-safe bulk
     * operations). Implementations SHOULD `yield` items as they are
     * produced rather than building a full array.
     *
     * @return iterable<int, EvidenceItem>
     */
    public function collect(CollectorContext $context): iterable;

    /**
     * Stable identifier for this collector — used by the registry, dossier
     * metadata, and audit logs. Lowercase, hyphen-separated. The convention
     * is the file name without the `Collector` suffix, lower-kebab-case:
     *   - `GitSourceCollector` -> `git-source`
     *   - `AiAttributionExtractor` -> `ai-attribution`
     *   - `DesignDocCollector` -> `design-doc`
     *   - `BranchSemanticsCollector` -> `branch-semantics`
     */
    public function name(): string;
}
