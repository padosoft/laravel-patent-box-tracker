<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Classifier;

use Illuminate\Support\Facades\Log;

/**
 * Pre-flight cost projection for a classifier run (PLAN-W4 §4.4).
 *
 * Why: classifying a 10-year-old monorepo at full price could
 * easily cost €500+. The guard projects the run BEFORE the first
 * LLM call and aborts early if the projection exceeds the
 * configured cap.
 *
 * Estimation model: per-commit input tokens × price-per-1k input
 * + per-commit output tokens × price-per-1k output. Tokens per
 * commit are an approximation calibrated against real Padosoft
 * runs: ~600 input tokens (system + commit metadata + evidence
 * tags), ~80 output tokens (one classification entry).
 *
 * Unknown models project at `null` (the call returns `null` and
 * the abort branch is a no-op + warning) so swapping in a new
 * model never breaks an existing run; the user must update the
 * price map to re-enable the cap.
 */
final class CostCapGuard
{
    private const TOKENS_PER_COMMIT_INPUT = 600;

    private const TOKENS_PER_COMMIT_OUTPUT = 80;

    /**
     * EUR per 1k tokens, conservatively rounded UP to the nearest
     * cent so the projection over-estimates rather than
     * under-estimates. Source: list prices as of 2026-04 for the
     * Padosoft default model surface.
     *
     * @var array<string, array{input: float, output: float}>
     */
    private const PRICE_PER_1K_EUR = [
        'claude-sonnet-4-6' => ['input' => 0.003, 'output' => 0.015],
        'claude-haiku-4-5' => ['input' => 0.00080, 'output' => 0.0040],
        'gpt-5-mini' => ['input' => 0.00025, 'output' => 0.0020],
        'gpt-5' => ['input' => 0.0025, 'output' => 0.0100],
        'regolo-it-medium' => ['input' => 0.00060, 'output' => 0.0030],
    ];

    /**
     * Project the cost of a run in EUR.
     *
     * Returns `null` for an unknown model — the caller should
     * surface the unknown-model fact and either abort
     * conservatively or proceed without a cap.
     */
    public function project(int $commitCount, string $model): ?float
    {
        if ($commitCount < 0) {
            $commitCount = 0;
        }

        $price = self::PRICE_PER_1K_EUR[$model] ?? null;
        if ($price === null) {
            return null;
        }

        $inputTokens = $commitCount * self::TOKENS_PER_COMMIT_INPUT;
        $outputTokens = $commitCount * self::TOKENS_PER_COMMIT_OUTPUT;

        $cost = ($inputTokens / 1000.0) * $price['input']
            + ($outputTokens / 1000.0) * $price['output'];

        return round($cost, 4);
    }

    /**
     * Throw {@see CostCapExceededException} when the projection
     * exceeds the cap. Unknown models log a warning and return
     * normally — the run proceeds without a hard cap.
     *
     * @throws CostCapExceededException
     */
    public function abortIfExceeded(int $commitCount, string $model, float $capEur): void
    {
        $projected = $this->project($commitCount, $model);
        if ($projected === null) {
            Log::stack(['stack', 'patent-box-tracker'])->warning(
                'CostCapGuard: unknown model, skipping pre-flight cap check.',
                [
                    'model' => $model,
                    'commit_count' => $commitCount,
                    'cap_eur' => $capEur,
                ],
            );

            return;
        }

        if ($projected > $capEur) {
            throw new CostCapExceededException(sprintf(
                'CostCapGuard: projected cost EUR %.4f for %d commits on model "%s" '
                .'exceeds the cap EUR %.2f. Lower the cap or trim the commit set.',
                $projected,
                $commitCount,
                $model,
                $capEur,
            ));
        }
    }

    /**
     * Whether this guard knows the price of the given model.
     * Useful for `--dry-run` output and tests.
     */
    public function knowsModel(string $model): bool
    {
        return isset(self::PRICE_PER_1K_EUR[$model]);
    }

    /**
     * @return list<string>
     */
    public function knownModels(): array
    {
        return array_keys(self::PRICE_PER_1K_EUR);
    }
}
