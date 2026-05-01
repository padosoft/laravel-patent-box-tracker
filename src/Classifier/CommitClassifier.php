<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use Illuminate\Support\Facades\Log;
use JsonException;

use function Laravel\Ai\agent;

/**
 * Wraps a single LLM call that classifies a batch of commits.
 *
 * Determinism contract (PLAN-W4 §4.1):
 *   - temperature is forced to 0
 *   - top_p forced to 1 by the underlying provider config
 *   - seed is read from config and passed through verbatim
 *   - the prompt is the single artefact captured by
 *     {@see ClassifierPrompts::PROMPT_VERSION}; never edit
 *     it without bumping the version
 *
 * Re-running the classifier on the same commit set with the
 * same model + seed + prompt version MUST produce the same
 * classifications (subject to provider-side guarantees).
 *
 * Transport: uses `Laravel\\Ai\\agent()` from the laravel/ai SDK
 * so any registered provider works (anthropic, openai, gemini,
 * regolo, ...). Tests fake the SDK's underlying HTTP calls via
 * `Http::fake()` against the provider's REST endpoint — see
 * `tests/Unit/Classifier/CommitClassifierTest.php`.
 */
final class CommitClassifier
{
    /**
     * Channels the warning lands in when the LLM emits a phase
     * outside the controlled taxonomy. Resolved via
     * {@see Log::stack()} so the package degrades gracefully
     * when neither the dedicated channel nor the default `stack`
     * channel is configured.
     */
    private const LOG_CHANNELS = ['stack', 'patent-box-tracker'];

    public function __construct(
        private readonly ClassifierPrompts $prompts,
        private readonly string $driver,
        private readonly string $model,
        /** @phpstan-ignore-next-line property is reserved for provider-side seeding wiring (W4.B.3+). */
        private readonly int $seed,
        private readonly int $timeoutSeconds = 60,
    ) {}

    /**
     * Classify a single batch of commits.
     *
     * @param  list<array<string, mixed>>  $commits  Per-commit metadata produced by the
     *                                               evidence collector pipeline. The keys are
     *                                               consumed verbatim by
     *                                               {@see ClassifierPrompts::buildUserPrompt()};
     *                                               see that method for the expected shape.
     * @param  array<string, list<string>>  $evidenceLinks  Map of commit SHA → list of
     *                                                      evidence slugs the collectors
     *                                                      associated to the commit.
     * @return array<string, CommitClassification> Keyed by 40-char hex SHA, in input
     *                                             order (PHP arrays preserve insertion
     *                                             order).
     *
     * @throws ClassifierResponseException When the LLM emits malformed JSON or a
     *                                     classification that fails schema validation.
     */
    public function classify(array $commits, array $evidenceLinks): array
    {
        if ($commits === []) {
            return [];
        }

        $systemPrompt = $this->prompts->buildSystemPrompt();
        $userPrompt = $this->prompts->buildUserPrompt($commits, $evidenceLinks);

        $rawText = $this->callLlm($systemPrompt, $userPrompt);

        $decoded = $this->decodeStrictJson($rawText);

        $classifications = [];
        foreach ($this->extractClassifications($decoded, $rawText) as $entry) {
            $classification = $this->buildClassification($entry, $rawText);
            $classifications[$classification->sha] = $classification;
        }

        return $classifications;
    }

    private function callLlm(string $systemPrompt, string $userPrompt): string
    {
        $response = agent($systemPrompt)
            ->prompt(
                prompt: $userPrompt,
                provider: $this->driver,
                model: $this->model,
                timeout: $this->timeoutSeconds,
            );

        return $response->text;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ClassifierResponseException
     */
    private function decodeStrictJson(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new ClassifierResponseException(
                'Classifier: LLM returned an empty response. Raw: '.$this->previewRaw($raw),
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ClassifierResponseException(
                'Classifier: LLM response is not valid JSON ('.$e->getMessage().'). Raw: '
                .$this->previewRaw($raw),
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new ClassifierResponseException(
                'Classifier: LLM response decoded to a non-array value. Raw: '.$this->previewRaw($raw),
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<array<string, mixed>>
     *
     * @throws ClassifierResponseException
     */
    private function extractClassifications(array $decoded, string $raw): array
    {
        $list = $decoded['classifications'] ?? null;
        if (! is_array($list)) {
            throw new ClassifierResponseException(
                'Classifier: LLM response is missing the "classifications" array. Raw: '
                .$this->previewRaw($raw),
            );
        }

        $entries = [];
        foreach ($list as $entry) {
            if (! is_array($entry)) {
                throw new ClassifierResponseException(
                    'Classifier: a classification entry is not an object. Raw: '
                    .$this->previewRaw($raw),
                );
            }
            /** @var array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     *
     * @throws ClassifierResponseException
     */
    private function buildClassification(array $entry, string $raw): CommitClassification
    {
        $sha = $this->stringField($entry, 'sha', $raw);
        if (! preg_match('/^[a-f0-9]{40}$/i', $sha)) {
            throw new ClassifierResponseException(sprintf(
                'Classifier: classification.sha "%s" is not a 40-char hex SHA. Raw: %s',
                $sha,
                $this->previewRaw($raw),
            ));
        }

        $phaseRaw = $this->stringField($entry, 'phase', $raw);
        $phase = Phase::tryFromString($phaseRaw);
        if ($phase === null) {
            $this->logUnknownPhase($sha, $phaseRaw);
            $phase = Phase::NonQualified;
        }

        $isQualified = (bool) ($entry['is_rd_qualified'] ?? false);

        $confidenceRaw = $entry['rd_qualification_confidence'] ?? null;
        if (! is_int($confidenceRaw) && ! is_float($confidenceRaw)) {
            throw new ClassifierResponseException(sprintf(
                'Classifier: classification.rd_qualification_confidence must be a number, got "%s". Raw: %s',
                get_debug_type($confidenceRaw),
                $this->previewRaw($raw),
            ));
        }
        $confidence = (float) $confidenceRaw;
        if ($confidence < 0.0) {
            $confidence = 0.0;
        } elseif ($confidence > 1.0) {
            $confidence = 1.0;
        }

        $rationale = $this->stringField($entry, 'rationale', $raw);

        $rejected = null;
        if (array_key_exists('rejected_phase', $entry) && $entry['rejected_phase'] !== null) {
            $rejectedRaw = $entry['rejected_phase'];
            if (! is_string($rejectedRaw)) {
                throw new ClassifierResponseException(sprintf(
                    'Classifier: classification.rejected_phase must be a string or null, got "%s". Raw: %s',
                    get_debug_type($rejectedRaw),
                    $this->previewRaw($raw),
                ));
            }
            $rejected = Phase::tryFromString($rejectedRaw);
            // Unknown rejected_phase silently coerces to null — it's a
            // tie-breaker, not a critical field.
        }

        $evidenceUsed = [];
        $evidenceRaw = $entry['evidence_used'] ?? [];
        if (is_array($evidenceRaw)) {
            foreach ($evidenceRaw as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $evidenceUsed[] = $slug;
                }
            }
        }

        return new CommitClassification(
            sha: strtolower($sha),
            phase: $phase,
            isRdQualified: $isQualified,
            rdQualificationConfidence: $confidence,
            rationale: $rationale,
            rejectedPhase: $rejected,
            evidenceUsed: $evidenceUsed,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     *
     * @throws ClassifierResponseException
     */
    private function stringField(array $entry, string $key, string $raw): string
    {
        $value = $entry[$key] ?? null;
        if (! is_string($value)) {
            throw new ClassifierResponseException(sprintf(
                'Classifier: classification.%s must be a string, got "%s". Raw: %s',
                $key,
                get_debug_type($value),
                $this->previewRaw($raw),
            ));
        }

        return $value;
    }

    private function previewRaw(string $raw): string
    {
        $clean = str_replace(["\r", "\n"], ' ', $raw);
        if (strlen($clean) <= 400) {
            return $clean;
        }

        return substr($clean, 0, 400).'... [truncated]';
    }

    private function logUnknownPhase(string $sha, string $phaseRaw): void
    {
        Log::stack(self::LOG_CHANNELS)->warning(
            'Classifier: LLM returned unknown phase; coercing to non_qualified.',
            [
                'sha' => $sha,
                'phase_raw' => $phaseRaw,
                'prompt_version' => ClassifierPrompts::PROMPT_VERSION,
            ],
        );
    }
}
