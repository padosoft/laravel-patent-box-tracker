<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Sources;

use DateTimeImmutable;
use Generator;
use InvalidArgumentException;

/**
 * Pluggable EvidenceCollector registry per R23 (boot-time FQCN validation +
 * non-overlap mutex on `supports()`).
 *
 * Validation runs in two phases:
 *
 *   1. **Boot-time / first-dispatch (static)** — every registered FQCN must
 *      `class_exists` and `is_subclass_of(EvidenceCollector::class)`. The
 *      registry then runs a best-effort mutex check against a single
 *      synthetic CollectorContext (a non-git temp directory) so the most
 *      common misconfigurations (two collectors that always return true)
 *      are caught at boot.
 *
 *   2. **Per-dispatch (dynamic)** — for every real CollectorContext, the
 *      registry filters collectors by `supports()`, then re-runs the
 *      pair-wise mutex on the SUPPORTING set. This catches context-sensitive
 *      overlaps that the synthetic boot fixture cannot exercise (a
 *      collector that only returns `true` when an actual git repository is
 *      present, for example).
 *
 * Overlap exemption: two collectors that intentionally share a context
 * (e.g. AiAttributionExtractor projecting GitSourceCollector) declare
 * the overlap via the optional `overlapsBy(): list<class-string>` method.
 * The registry treats an exemption as a contract: BOTH collectors must
 * still emit DISTINCT EvidenceItem kinds, which the EvidenceItem kind
 * constants enforce structurally.
 *
 * The cost of eager validation is a class-existence check per FQCN plus
 * one synthetic `supports()` call per pair — small enough that lazy/eager
 * is mostly stylistic. We chose lazy so a misconfigured
 * `config/patent-box-tracker.php` does not crash a `php artisan` boot in
 * unrelated commands.
 */
final class CollectorRegistry
{
    /**
     * @var list<class-string<EvidenceCollector>>
     */
    private array $collectorFqcns;

    /**
     * @var list<EvidenceCollector>|null
     */
    private ?array $resolvedCollectors = null;

    private bool $validated = false;

    /**
     * @param  list<class-string<EvidenceCollector>>|array<int, class-string<EvidenceCollector>>  $collectorFqcns
     */
    public function __construct(array $collectorFqcns)
    {
        $this->collectorFqcns = array_values($collectorFqcns);
    }

    /**
     * Dispatch the context across the registered collectors and yield every
     * EvidenceItem any collector emits. Triggers boot-time validation on
     * first call; subsequent calls re-use the validated instances.
     *
     * Per-dispatch the registry ALSO re-runs the supports() mutex against
     * the actual context (not the synthetic boot fixture), so a
     * context-sensitive overlap that slipped through validate() — for
     * example, two collectors that both return true only when a real `.git`
     * directory is present — is caught here before the dispatch produces
     * conflicting evidence.
     *
     * @return Generator<int, EvidenceItem>
     */
    public function dispatch(CollectorContext $context): Generator
    {
        $this->validate();

        // PHPStan: validate() always sets resolvedCollectors when it
        // succeeds, but the property type stays nullable. Coerce to a
        // non-null local for the generator.
        $collectors = $this->resolvedCollectors ?? [];

        $supporting = [];
        foreach ($collectors as $collector) {
            if ($collector->supports($context)) {
                $supporting[] = $collector;
            }
        }

        $this->guardSupportsMutexForRealContext($supporting, $context);

        foreach ($supporting as $collector) {
            foreach ($collector->collect($context) as $item) {
                yield $item;
            }
        }
    }

    /**
     * @param  list<EvidenceCollector>  $supporting  Collectors whose `supports()` returned
     *                                               true for the actual context.
     */
    private function guardSupportsMutexForRealContext(array $supporting, CollectorContext $context): void
    {
        $count = count($supporting);
        if ($count < 2) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $supporting[$i];
                $b = $supporting[$j];

                if ($this->isExempted($a, $b)) {
                    continue;
                }

                throw new InvalidArgumentException(sprintf(
                    'CollectorRegistry: collectors "%s" (%s) and "%s" (%s) BOTH return true from supports() '
                    .'for the dispatch context "%s" (role=%s). R23 forbids non-exempt overlap; either narrow '
                    .'one collector\'s supports() predicate or declare the overlap via overlapsBy().',
                    $a::class,
                    $a->name(),
                    $b::class,
                    $b->name(),
                    $context->repositoryPath,
                    $context->repositoryRole,
                ));
            }
        }
    }

    /**
     * @return list<EvidenceCollector>
     */
    public function collectors(): array
    {
        $this->validate();

        return $this->resolvedCollectors ?? [];
    }

    /**
     * Run boot-time validation. Idempotent — subsequent calls are no-ops.
     *
     * @throws InvalidArgumentException When an FQCN does not implement the
     *                                  interface or when two collectors
     *                                  overlap on `supports()` without an
     *                                  `overlapsBy()` exemption.
     */
    public function validate(): void
    {
        if ($this->validated) {
            return;
        }

        $instances = [];
        foreach ($this->collectorFqcns as $fqcn) {
            if (! class_exists($fqcn)) {
                throw new InvalidArgumentException(sprintf(
                    'CollectorRegistry: registered collector "%s" does not exist (config: patent-box-tracker.collectors).',
                    $fqcn,
                ));
            }
            if (! is_subclass_of($fqcn, EvidenceCollector::class)) {
                throw new InvalidArgumentException(sprintf(
                    'CollectorRegistry: registered collector "%s" must implement %s (config: patent-box-tracker.collectors).',
                    $fqcn,
                    EvidenceCollector::class,
                ));
            }

            /** @var EvidenceCollector $instance */
            $instance = new $fqcn;
            $instances[] = $instance;
        }

        $this->guardSupportsMutex($instances);

        $this->resolvedCollectors = $instances;
        $this->validated = true;
    }

    /**
     * @param  list<EvidenceCollector>  $instances
     */
    private function guardSupportsMutex(array $instances): void
    {
        $count = count($instances);
        if ($count < 2) {
            return;
        }

        $fixture = self::buildMutexFixture();

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $instances[$i];
                $b = $instances[$j];

                if ($this->isExempted($a, $b)) {
                    continue;
                }

                $aSupports = $this->safeSupports($a, $fixture);
                $bSupports = $this->safeSupports($b, $fixture);

                if ($aSupports && $bSupports) {
                    throw new InvalidArgumentException(sprintf(
                        'CollectorRegistry: collectors "%s" (%s) and "%s" (%s) BOTH return true from supports() '
                        .'for the same context — overlap is forbidden by R23. Either narrow one of them '
                        .'or declare the overlap explicitly via overlapsBy().',
                        $a::class,
                        $a->name(),
                        $b::class,
                        $b->name(),
                    ));
                }
            }
        }
    }

    private function safeSupports(EvidenceCollector $collector, CollectorContext $context): bool
    {
        try {
            return $collector->supports($context);
        } catch (\Throwable) {
            // A collector that throws on the synthetic context is
            // implicitly "does not support" — fine for the mutex check.
            return false;
        }
    }

    private function isExempted(EvidenceCollector $a, EvidenceCollector $b): bool
    {
        if (method_exists($a, 'overlapsBy')) {
            /** @var list<class-string<EvidenceCollector>> $exempt */
            $exempt = $a->overlapsBy();
            if (in_array($b::class, $exempt, true)) {
                return true;
            }
        }
        if (method_exists($b, 'overlapsBy')) {
            /** @var list<class-string<EvidenceCollector>> $exempt */
            $exempt = $b->overlapsBy();
            if (in_array($a::class, $exempt, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a synthetic CollectorContext that represents a "plausible"
     * tracking session. Used only for the boot-time mutex check; never
     * surfaces in real evidence streams.
     *
     * The fixture uses a freshly-created sub-directory of the system temp
     * directory so:
     *   - `is_dir()` returns true → DesignDocCollector::supports() may
     *      return true (which is fine on its own).
     *   - `git rev-parse` walks up and finds NO `.git`, so the git-backed
     *      collectors return false and do not falsely overlap with
     *      DesignDocCollector.
     */
    private static function buildMutexFixture(): CollectorContext
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'patent-box-mutex-fixture';
        if (! is_dir($base) && ! @mkdir($base, 0755, true) && ! is_dir($base)) {
            // Fall back to the temp dir itself if we cannot create a sub
            // dir — tests on locked-down filesystems still get a non-git
            // path most of the time.
            $base = sys_get_temp_dir();
        }

        return new CollectorContext(
            repositoryPath: $base,
            repositoryRole: 'support',
            branch: null,
            periodFrom: new DateTimeImmutable('1970-01-01T00:00:00Z'),
            periodTo: new DateTimeImmutable('2099-12-31T23:59:59Z'),
            excludedAuthors: [],
        );
    }
}
