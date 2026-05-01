<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Sources;

use Padosoft\PatentBoxTracker\Sources\AiAttributionExtractor;
use Padosoft\PatentBoxTracker\Sources\GitSourceCollector;
use Padosoft\PatentBoxTracker\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AiAttributionExtractorTest extends TestCase
{
    /**
     * 12 commit-message variants covering the realistic surface of
     * AI-attribution markers and the no-marker baseline.
     *
     * @return iterable<string, array{0:string,1:string,2:string,3:string,4?:float}>
     */
    public static function commitVariants(): iterable
    {
        yield 'human-only commit, no trailers, no bot email' => [
            "feat: add the parser entrypoint\n\nLong-form description.",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_HUMAN,
            1.0,
        ];

        yield 'claude co-authored-by trailer (canonical Padosoft form)' => [
            "feat: chunker FSM\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_AI_ASSISTED,
            1.0,
        ];

        yield 'copilot co-authored-by trailer' => [
            "feat: edge weighting\n\nCo-Authored-By: GitHub Copilot <bot@github.com>",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_AI_ASSISTED,
            1.0,
        ];

        yield 'multi-trailer (claude + copilot together) → mixed' => [
            "feat: hybrid implementation\n\nCo-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>\nCo-Authored-By: GitHub Copilot <bot@github.com>",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_MIXED,
            0.5,
        ];

        yield 'inline AI-Tool: trailer (future-proof convention)' => [
            "feat: prompt tuner\n\nAI-Tool: claude-opus-4-7",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_AI_ASSISTED,
            1.0,
        ];

        yield 'inline AI: trailer (compact form)' => [
            "fix: typo\n\nAI: claude-sonnet-4-6",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_AI_ASSISTED,
            1.0,
        ];

        yield 'author email is anthropic noreply (no trailer) → ai authored, low conf' => [
            "feat: auto-generated\n\nNo trailer here.",
            'noreply@anthropic.com',
            'noreply@anthropic.com',
            AiAttributionExtractor::ATTRIBUTION_AI_AUTHORED,
            0.7,
        ];

        yield 'author email is anthropic + trailer present → ai authored, full conf' => [
            "feat: auto-generated\n\nCo-Authored-By: Claude <noreply@anthropic.com>",
            'noreply@anthropic.com',
            'noreply@anthropic.com',
            AiAttributionExtractor::ATTRIBUTION_AI_AUTHORED,
            1.0,
        ];

        yield 'author email is bot@github.com → ai authored' => [
            'chore: lockfile bump',
            'bot@github.com',
            'bot@github.com',
            AiAttributionExtractor::ATTRIBUTION_AI_AUTHORED,
            0.7,
        ];

        yield 'human author + bot committer + ai trailer → mixed' => [
            "feat: edge case\n\nCo-Authored-By: Claude <noreply@anthropic.com>",
            'lorenzo.padovani@padosoft.com',
            'noreply@anthropic.com',
            AiAttributionExtractor::ATTRIBUTION_MIXED,
            0.5,
        ];

        yield 'case-insensitive trailer match (lowercase prefix)' => [
            "feat: lowercased\n\nco-authored-by: Claude <noreply@anthropic.com>",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_AI_ASSISTED,
            1.0,
        ];

        yield 'name-only signature (no email match) — Claude in name' => [
            "feat: signature only\n\nCo-Authored-By: Claude AI Assistant <fake@example.com>",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
            AiAttributionExtractor::ATTRIBUTION_AI_ASSISTED,
            1.0,
        ];
    }

    #[DataProvider('commitVariants')]
    public function test_classify(string $message, string $authorEmail, string $committerEmail, string $expectedAttribution, float $expectedConfidence): void
    {
        $result = AiAttributionExtractor::classify($message, $authorEmail, $committerEmail);

        $this->assertSame(
            $expectedAttribution,
            $result['attribution'],
            sprintf(
                'Attribution mismatch for variant. Got "%s" expected "%s". Markers: %s',
                $result['attribution'],
                $expectedAttribution,
                json_encode($result['markers'] ?? [], JSON_THROW_ON_ERROR),
            ),
        );
        $this->assertEqualsWithDelta(
            $expectedConfidence,
            $result['confidence'],
            0.001,
            'Confidence outside delta.',
        );
        $this->assertIsArray($result['markers']);
    }

    public function test_collector_name_is_stable(): void
    {
        $this->assertSame('ai-attribution', (new AiAttributionExtractor)->name());
    }

    public function test_overlaps_by_declares_intentional_git_overlap(): void
    {
        $extractor = new AiAttributionExtractor;
        $this->assertContains(
            GitSourceCollector::class,
            $extractor->overlapsBy(),
        );
    }

    public function test_human_baseline_emits_no_markers(): void
    {
        $result = AiAttributionExtractor::classify(
            "feat: pure human work\n\nWritten with my own hands.",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
        );

        $this->assertSame(AiAttributionExtractor::ATTRIBUTION_HUMAN, $result['attribution']);
        $this->assertSame([], $result['markers']);
    }

    public function test_classify_extracts_explicit_marker_substrings(): void
    {
        $result = AiAttributionExtractor::classify(
            "feat: chunker\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>",
            'lorenzo.padovani@padosoft.com',
            'lorenzo.padovani@padosoft.com',
        );

        $this->assertContains('co-authored-by:noreply@anthropic.com', $result['markers']);
    }
}
