<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Padosoft\PatentBoxTracker\Classifier\ClassifierPrompts;
use Padosoft\PatentBoxTracker\Classifier\ClassifierResponseException;
use Padosoft\PatentBoxTracker\Classifier\CommitClassifier;
use Padosoft\PatentBoxTracker\Classifier\Phase;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class CommitClassifierTest extends TestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_classify_parses_strict_json_into_typed_classifications(): void
    {
        $cannedJson = json_encode([
            'classifications' => [
                [
                    'sha' => self::SHA_A,
                    'phase' => 'implementation',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => 0.92,
                    'rationale' => 'Realises designed component.',
                    'rejected_phase' => 'design',
                    'evidence_used' => ['plan:PLAN-W3'],
                ],
                [
                    'sha' => self::SHA_B,
                    'phase' => 'non_qualified',
                    'is_rd_qualified' => false,
                    'rd_qualification_confidence' => 0.95,
                    'rationale' => 'Pure CI plumbing.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->fakeAnthropicResponse($cannedJson);

        $classifier = $this->makeClassifier();
        $result = $classifier->classify(
            [
                $this->commitFixture(self::SHA_A, 'feat: A'),
                $this->commitFixture(self::SHA_B, 'chore: B'),
            ],
            [],
        );

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(self::SHA_A, $result);
        $this->assertArrayHasKey(self::SHA_B, $result);

        $a = $result[self::SHA_A];
        $this->assertSame(Phase::Implementation, $a->phase);
        $this->assertTrue($a->isRdQualified);
        $this->assertSame(0.92, $a->rdQualificationConfidence);
        $this->assertSame(Phase::Design, $a->rejectedPhase);
        $this->assertSame(['plan:PLAN-W3'], $a->evidenceUsed);

        $b = $result[self::SHA_B];
        $this->assertSame(Phase::NonQualified, $b->phase);
        $this->assertFalse($b->isRdQualified);
        $this->assertNull($b->rejectedPhase);

        Http::assertSentCount(1);
    }

    public function test_classify_throws_classifier_response_exception_on_malformed_json(): void
    {
        $this->fakeAnthropicResponse('this is not json at all');

        $classifier = $this->makeClassifier();

        $this->expectException(ClassifierResponseException::class);
        $this->expectExceptionMessageMatches('/not valid JSON|empty response|missing the "classifications"/');

        $classifier->classify([$this->commitFixture(self::SHA_A, 'feat: A')], []);
    }

    public function test_classify_throws_when_classifications_key_missing(): void
    {
        $this->fakeAnthropicResponse(json_encode(['unrelated' => true], JSON_THROW_ON_ERROR));

        $classifier = $this->makeClassifier();

        $this->expectException(ClassifierResponseException::class);
        $this->expectExceptionMessageMatches('/missing the "classifications" array/');

        $classifier->classify([$this->commitFixture(self::SHA_A, 'feat: A')], []);
    }

    public function test_unknown_phase_value_falls_back_to_non_qualified_and_logs_warning(): void
    {
        Log::shouldReceive('stack')
            ->once()
            ->with(['stack', 'patent-box-tracker'])
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Classifier: LLM returned unknown phase; coercing to non_qualified.',
                \Mockery::on(static function (array $context): bool {
                    return ($context['phase_raw'] ?? null) === 'speculative_research'
                        && ($context['prompt_version'] ?? null) === 'patent-box-classifier-v1';
                }),
            );

        $cannedJson = json_encode([
            'classifications' => [
                [
                    'sha' => self::SHA_A,
                    'phase' => 'speculative_research',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => 0.5,
                    'rationale' => 'Hallucinated phase value.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->fakeAnthropicResponse($cannedJson);

        $classifier = $this->makeClassifier();
        $result = $classifier->classify([$this->commitFixture(self::SHA_A, 'feat: A')], []);

        $this->assertSame(Phase::NonQualified, $result[self::SHA_A]->phase);
    }

    public function test_confidence_is_clamped_to_unit_interval(): void
    {
        $cannedJson = json_encode([
            'classifications' => [
                [
                    'sha' => self::SHA_A,
                    'phase' => 'implementation',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => 1.7, // out-of-range
                    'rationale' => 'Confidence over 1.0.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ],
                [
                    'sha' => self::SHA_B,
                    'phase' => 'implementation',
                    'is_rd_qualified' => true,
                    'rd_qualification_confidence' => -0.2, // out-of-range
                    'rationale' => 'Confidence under 0.0.',
                    'rejected_phase' => null,
                    'evidence_used' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->fakeAnthropicResponse($cannedJson);

        $classifier = $this->makeClassifier();
        $result = $classifier->classify(
            [
                $this->commitFixture(self::SHA_A, 'feat: A'),
                $this->commitFixture(self::SHA_B, 'feat: B'),
            ],
            [],
        );

        $this->assertSame(1.0, $result[self::SHA_A]->rdQualificationConfidence);
        $this->assertSame(0.0, $result[self::SHA_B]->rdQualificationConfidence);
    }

    public function test_empty_commit_list_short_circuits_with_no_http_call(): void
    {
        Http::fake();

        $classifier = $this->makeClassifier();
        $result = $classifier->classify([], []);

        $this->assertSame([], $result);
        Http::assertNothingSent();
    }

    private function makeClassifier(): CommitClassifier
    {
        return new CommitClassifier(
            prompts: new ClassifierPrompts,
            driver: 'anthropic',
            model: 'claude-sonnet-4-6',
            seed: 0xC0DEC0DE,
            timeoutSeconds: 30,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function commitFixture(string $sha, string $subject): array
    {
        return [
            'sha' => $sha,
            'subject' => $subject,
            'body' => '',
            'author_email' => 'lorenzo.padovani@padosoft.com',
            'committed_at' => '2026-04-01T10:00:00Z',
            'branch' => 'feature/v4.0-W4.B.2',
            'branch_semantics' => '',
            'ai_attribution' => 'human',
            'files_changed' => ['src/example.php'],
        ];
    }

    private function fakeAnthropicResponse(string $textBody): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => $textBody],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 50,
                ],
            ], 200),
        ]);
    }
}
