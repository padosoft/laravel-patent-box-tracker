<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

/**
 * Single classification outcome for one commit.
 *
 * Readonly per PLAN-W4 §4.1 — once produced, a classification is
 * an immutable audit-trail row. The `rationale` and
 * `evidence_used` fields exist to satisfy the documentation
 * regime (D.M. 6 ottobre 2022 art. 4): an Agenzia delle Entrate
 * auditor must be able to read WHY a commit was placed in its
 * phase without re-running the LLM.
 *
 * The class is `readonly` (PHP 8.2+) and `final` so downstream
 * pipeline stages cannot accidentally mutate the audit trail.
 */
final readonly class CommitClassification
{
    /**
     * @param  string  $sha  40-char hex commit SHA the classification applies to.
     * @param  Phase  $phase  Patent Box phase taxonomy. Coerced to `Phase::NonQualified`
     *                        when the LLM emits an unknown value (the classifier
     *                        logs a warning in that branch — see CommitClassifier).
     * @param  bool  $isRdQualified  Whether the commit counts toward the 110% R&D
     *                               super-deduction. Always `false` when phase ===
     *                               `Phase::NonQualified`; may be `false` for one
     *                               of the qualified phases when the LLM judges
     *                               the activity too ancillary to qualify.
     * @param  float  $rdQualificationConfidence  LLM-reported confidence on the
     *                                            qualified-or-not decision, in `[0, 1]`.
     *                                            Clamped to that range at parse time.
     * @param  string  $rationale  1-3 sentences explaining the classification, intended
     *                             for an auditor reading the dossier. Truncated at
     *                             render time to fit dossier columns.
     * @param  Phase|null  $rejectedPhase  When the LLM had a tie between two phases,
     *                                     the runner-up. `null` when the decision was
     *                                     unambiguous.
     * @param  list<string>  $evidenceUsed  Slugs / identifiers of the evidence items
     *                                      the LLM cited (`plan:PLAN-W3`, `adr:0007`,
     *                                      `branch:feature/v4.0-W3.1`, ...). Free-form
     *                                      strings keyed by collector convention; the
     *                                      renderer cross-references them with the
     *                                      `tracked_evidence` table.
     */
    public function __construct(
        public string $sha,
        public Phase $phase,
        public bool $isRdQualified,
        public float $rdQualificationConfidence,
        public string $rationale,
        public ?Phase $rejectedPhase,
        public array $evidenceUsed,
    ) {}

    /**
     * @return array{
     *     sha: string,
     *     phase: string,
     *     is_rd_qualified: bool,
     *     rd_qualification_confidence: float,
     *     rationale: string,
     *     rejected_phase: string|null,
     *     evidence_used: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'sha' => $this->sha,
            'phase' => $this->phase->value,
            'is_rd_qualified' => $this->isRdQualified,
            'rd_qualification_confidence' => $this->rdQualificationConfidence,
            'rationale' => $this->rationale,
            'rejected_phase' => $this->rejectedPhase?->value,
            'evidence_used' => $this->evidenceUsed,
        ];
    }
}
