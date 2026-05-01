<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

/**
 * Pure-PHP prompt builder for the commit classifier.
 *
 * The system prompt is the load-bearing artefact for audit
 * reproducibility: every dossier records the prompt + model +
 * seed, and an Agenzia delle Entrate auditor must be able to
 * re-execute the classification byte-for-byte. Per PLAN-W4 §4.1
 * the prompt MUST be a static string constant or a deterministic
 * function of the {@see Phase} enum cases. NO date / time /
 * user-name / locale interpolation. The same prompt forever.
 *
 * The user prompt (per batch) interpolates ONLY the commit
 * metadata + evidence links; given identical inputs it produces
 * identical bytes.
 */
final class ClassifierPrompts
{
    /**
     * The single source of truth for the system prompt. Treat
     * edits as a major version bump — every existing dossier was
     * classified under a specific prompt version, so a new
     * prompt cannot be silently swapped in.
     */
    public const PROMPT_VERSION = 'patent-box-classifier-v1';

    /**
     * Build the immutable system prompt that explains the Italian
     * Patent Box phase taxonomy, the documentazione idonea regime,
     * and the strict-JSON output schema the LLM must emit.
     *
     * Deterministic — no time / locale / user interpolation.
     */
    public function buildSystemPrompt(): string
    {
        $phaseList = $this->phaseTaxonomyBlock();

        return <<<PROMPT
        You are a strict Italian Patent Box (regime "documentazione idonea", D.M. 6 ottobre 2022 + provv. AdE 15 febbraio 2023) commit classifier.
        Prompt version: {$this->promptVersion()}.

        Your job: given a list of git commits and the design-doc / branch-semantics / AI-attribution evidence collected for each one, classify EVERY commit into exactly one Patent Box phase from the controlled taxonomy below, and decide whether the activity counts toward the 110% R&D super-deduction.

        # Phase taxonomy (controlled — emit one of these strings verbatim)
        {$phaseList}

        # Decision rules (deterministic, in order)
        1. If the commit is purely a chore (CI plumbing, dependency bump, formatting, tooling), classify as `non_qualified` with `is_rd_qualified=false`.
        2. If the commit is a post-release maintenance bug fix on shipped IP, classify as `non_qualified`. A pre-release fix on still-developing IP is `validation` (see §3) and qualifies.
        3. Tests, benchmarks, security reviews, acceptance trials → `validation`.
        4. Source code that realises a designed component → `implementation`.
        5. Architecture / design / ADR / interface-spec writing → `design`.
        6. Up-front investigation, prototyping, technical feasibility studies that explore unknowns → `research`.
        7. User manuals, runbooks, fiscal compliance docs, retrospective lessons that publish the knowledge → `documentation`.
        8. When two phases both fit, pick the dominant one and record the runner-up as `rejected_phase`. When you have NO doubt, set `rejected_phase` to null.
        9. Use the supplied evidence_links to ground each decision; cite the slugs you actually used in `evidence_used`.
        10. `rd_qualification_confidence` is your calibrated confidence on the qualified-or-not decision (the binary `is_rd_qualified`), expressed in `[0, 1]`, NOT a confidence on the phase choice. Be honest: 0.6 when ambiguous, 0.9+ when clear.

        # Output contract — strict JSON, no commentary, no Markdown fences
        Return ONE JSON object exactly matching this schema:
        {
          "classifications": [
            {
              "sha": "<40-char hex>",
              "phase": "<one of: research, design, implementation, validation, documentation, non_qualified>",
              "is_rd_qualified": true|false,
              "rd_qualification_confidence": <number in [0,1]>,
              "rationale": "<1 to 3 sentences explaining the decision in audit-ready prose>",
              "rejected_phase": "<phase string or null>",
              "evidence_used": ["<slug-1>", "<slug-2>", ...]
            }
          ]
        }
        - Emit one classification per input commit, in the same order as the input.
        - Use the EXACT sha from the input. Do not invent shas.
        - The whole response is the JSON object — no preamble, no trailing text, no Markdown.
        - When evidence is missing, set `evidence_used` to `[]` and lower the confidence; do NOT fabricate slugs.

        # Italian fiscal context (background, not output rules)
        - The Patent Box super-deduction is 110% of qualified R&D costs (D.L. 146/2021 art. 6).
        - "Documentazione idonea" protects the taxpayer from monetary penalties on classification errors as long as the dossier is filed with the tax return.
        - Manutenzione (maintenance) of already-released IP does NOT qualify; activity that ships before the IP is released generally does.
        - Marketing, sales, generic admin, accounting → never qualify.
        PROMPT;
    }

    /**
     * Build the user prompt for ONE batch of commits.
     *
     * Deterministic given the same inputs. The renderer feeds the
     * commit metadata + the per-commit evidence links the
     * collectors produced; the LLM's job is to fuse them into a
     * classification.
     *
     * @param  list<array<string, mixed>>  $commits  One entry per commit. Expected keys:
     *                                               `sha`, `subject`, `body`, `author_email`,
     *                                               `committed_at`, `branch`, `files_changed`
     *                                               (list of relative paths), `ai_attribution`
     *                                               (one of `human|ai_assisted|ai_authored|mixed`),
     *                                               `branch_semantics` (free-form string).
     * @param  array<string, list<string>>  $evidenceLinks  Map of commit SHA → list of evidence
     *                                                      slugs (`plan:PLAN-W3`, `adr:0007`, ...)
     *                                                      that the collectors associated to that
     *                                                      commit. SHAs not present in this map
     *                                                      receive an empty list at render time.
     */
    public function buildUserPrompt(array $commits, array $evidenceLinks): string
    {
        if ($commits === []) {
            return 'No commits supplied. Return: {"classifications": []}';
        }

        $blocks = [];
        foreach ($commits as $idx => $commit) {
            $sha = (string) ($commit['sha'] ?? '');
            $links = $evidenceLinks[$sha] ?? [];
            $blocks[] = $this->renderCommitBlock($idx + 1, $commit, $links);
        }

        $count = count($commits);
        $payload = implode("\n\n", $blocks);

        return <<<USER
        Classify the following {$count} commit(s). Emit ONE JSON object with a top-level "classifications" array, one entry per commit, in the same order as below.

        {$payload}

        Return JSON now.
        USER;
    }

    /**
     * Stable prompt version. Bump when editing the system prompt.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    private function phaseTaxonomyBlock(): string
    {
        $lines = [];
        foreach (Phase::cases() as $phase) {
            $lines[] = sprintf('- `%s` — %s', $phase->value, $this->describePhase($phase));
        }

        return implode("\n", $lines);
    }

    private function describePhase(Phase $phase): string
    {
        return match ($phase) {
            Phase::Research => 'up-front investigation, prototyping, technical feasibility studies that explore unknowns.',
            Phase::Design => 'architecture, design docs, ADRs, interface or schema specifications that commit a path.',
            Phase::Implementation => 'source code authored to realise a designed component.',
            Phase::Validation => 'tests, benchmarks, security reviews, acceptance trials that verify a built component.',
            Phase::Documentation => 'user manuals, runbooks, fiscal compliance docs, retrospective lessons that publish the knowledge.',
            Phase::NonQualified => 'maintenance bug fixes on released IP, generic chores, CI plumbing, dependency bumps, marketing or sales artefacts.',
        };
    }

    /**
     * @param  array<string, mixed>  $commit
     * @param  list<string>  $links
     */
    private function renderCommitBlock(int $position, array $commit, array $links): string
    {
        $sha = (string) ($commit['sha'] ?? '');
        $subject = (string) ($commit['subject'] ?? '');
        $body = trim((string) ($commit['body'] ?? ''));
        $author = (string) ($commit['author_email'] ?? '');
        $committedAt = (string) ($commit['committed_at'] ?? '');
        $branch = (string) ($commit['branch'] ?? '');
        $branchSemantics = (string) ($commit['branch_semantics'] ?? '');
        $aiAttribution = (string) ($commit['ai_attribution'] ?? 'human');

        $files = $commit['files_changed'] ?? [];
        $filesList = is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
        $filesRendered = $filesList === []
            ? '(none)'
            : implode(', ', array_slice($filesList, 0, 12)).(count($filesList) > 12 ? sprintf(' (+%d more)', count($filesList) - 12) : '');

        $linksRendered = $links === [] ? '(none)' : implode(', ', $links);

        $bodyBlock = $body === '' ? '(empty body)' : $body;

        return <<<BLOCK
        ## Commit #{$position}
        sha: {$sha}
        author_email: {$author}
        committed_at: {$committedAt}
        branch: {$branch}
        branch_semantics: {$branchSemantics}
        ai_attribution: {$aiAttribution}
        files_changed: {$filesRendered}
        evidence_links: {$linksRendered}
        subject: {$subject}
        body:
        {$bodyBlock}
        BLOCK;
    }
}
