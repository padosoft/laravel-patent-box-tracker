<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use InvalidArgumentException;

/**
 * Patent Box phase taxonomy per Agenzia delle Entrate guidance
 * (D.M. 6 ottobre 2022 + provv. AdE 15 febbraio 2023).
 *
 * The six values map to the activity categories that the
 * "documentazione idonea" regime expects to see in a software-IP
 * dossier:
 *
 *  - research:       up-front investigation, prototyping, technical
 *                    feasibility studies that EXPLORE the problem
 *                    space.
 *  - design:         architecture, design docs, ADRs, schema /
 *                    interface specifications that COMMIT a path.
 *  - implementation: source code authored to realise a designed
 *                    component.
 *  - validation:     tests, benchmarks, security reviews,
 *                    acceptance trials that VERIFY a built
 *                    component.
 *  - documentation:  user manuals, runbooks, fiscal compliance
 *                    docs, retrospective lessons that PUBLISH the
 *                    knowledge.
 *  - non_qualified:  bug fixes after release, generic chores, CI
 *                    plumbing, dependency bumps, marketing or sales
 *                    artefacts. Do NOT count toward the 110%
 *                    super-deduction.
 *
 * The order of the cases mirrors the typical lifecycle of a
 * Patent-Box-qualifying R&D activity. Auditors expect the five
 * qualified phases to be represented (even thinly) over the
 * fiscal year.
 */
enum Phase: string
{
    case Research = 'research';
    case Design = 'design';
    case Implementation = 'implementation';
    case Validation = 'validation';
    case Documentation = 'documentation';
    case NonQualified = 'non_qualified';

    /**
     * Coerce the given string to a Phase, throwing on unknown
     * values. Use {@see tryFromString()} when graceful fallback
     * is desired (the classifier uses it to coerce LLM drift to
     * `non_qualified` rather than aborting the run).
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $phase = self::tryFrom($value);
        if ($phase === null) {
            throw new InvalidArgumentException(sprintf(
                'Phase: "%s" is not a valid value. Allowed: [%s].',
                $value,
                implode(', ', array_map(static fn (self $p): string => $p->value, self::cases())),
            ));
        }

        return $phase;
    }

    /**
     * Lenient coercion — returns null on unknown values instead of
     * throwing. The classifier uses this at parse time to detect
     * LLM phase drift and fall back to `non_qualified` rather than
     * aborting an otherwise-good batch.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Whether this phase counts toward the 110% R&D
     * super-deduction. `non_qualified` is the only `false` case;
     * the five lifecycle phases all qualify.
     */
    public function qualifies(): bool
    {
        return $this !== self::NonQualified;
    }
}
