<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use Padosoft\PatentBoxTracker\Classifier\ClassifierPrompts;
use PHPUnit\Framework\TestCase;

final class ClassifierPromptsTest extends TestCase
{
    public function test_system_prompt_matches_committed_golden_snapshot(): void
    {
        $expected = (string) file_get_contents(__DIR__.'/../../fixtures/prompts/system.txt');
        $actual = (new ClassifierPrompts)->buildSystemPrompt();

        // Byte-exact match — the system prompt is load-bearing for
        // audit reproducibility. Any drift bumps PROMPT_VERSION
        // and refreshes the golden snapshot.
        $this->assertSame(
            $expected,
            $actual,
            'System prompt drift detected. Either bump ClassifierPrompts::PROMPT_VERSION '
            .'and refresh tests/fixtures/prompts/system.txt, or revert the prompt change.',
        );
    }

    public function test_system_prompt_lists_all_six_phases_verbatim(): void
    {
        $prompt = (new ClassifierPrompts)->buildSystemPrompt();

        foreach (['research', 'design', 'implementation', 'validation', 'documentation', 'non_qualified'] as $phase) {
            $this->assertStringContainsString('`'.$phase.'`', $prompt, "phase '{$phase}' must appear in the prompt");
        }
    }

    public function test_system_prompt_carries_version_marker(): void
    {
        $prompt = (new ClassifierPrompts)->buildSystemPrompt();

        $this->assertStringContainsString('Prompt version: patent-box-classifier-v1.', $prompt);
        $this->assertSame('patent-box-classifier-v1', (new ClassifierPrompts)->promptVersion());
    }

    public function test_user_prompt_is_deterministic_for_identical_input(): void
    {
        $commits = [
            $this->commitFixture('aaaaaaaa', 'feat: implement A', 'body A', 'src/A.php'),
            $this->commitFixture('bbbbbbbb', 'docs: design doc', 'body B', 'docs/PLAN.md'),
        ];

        $links = [
            'aaaaaaaa' => ['plan:PLAN-W3', 'ai-attribution:ai_assisted'],
            'bbbbbbbb' => [],
        ];

        $a = (new ClassifierPrompts)->buildUserPrompt($commits, $links);
        $b = (new ClassifierPrompts)->buildUserPrompt($commits, $links);

        $this->assertSame($a, $b);
    }

    public function test_user_prompt_contains_json_schema_directive_and_each_sha(): void
    {
        $commits = [
            $this->commitFixture('aaaaaaaa', 'subject one', '', 'src/file.php'),
            $this->commitFixture('bbbbbbbb', 'subject two', '', 'docs/PLAN-W4.md'),
        ];

        $userPrompt = (new ClassifierPrompts)->buildUserPrompt($commits, []);

        $this->assertStringContainsString('aaaaaaaa', $userPrompt);
        $this->assertStringContainsString('bbbbbbbb', $userPrompt);
        $this->assertStringContainsString('Return JSON now.', $userPrompt);
        // The user prompt asks for the structured-output schema by reference;
        // the FULL JSON schema lives in the SYSTEM prompt.
        $this->assertStringContainsString('"classifications"', $userPrompt);
    }

    public function test_user_prompt_handles_empty_commit_list(): void
    {
        $userPrompt = (new ClassifierPrompts)->buildUserPrompt([], []);

        $this->assertStringContainsString('"classifications": []', $userPrompt);
    }

    /**
     * @return array<string, mixed>
     */
    private function commitFixture(string $sha, string $subject, string $body, string $file): array
    {
        return [
            'sha' => $sha,
            'subject' => $subject,
            'body' => $body,
            'author_email' => 'lorenzo.padovani@padosoft.com',
            'committed_at' => '2026-04-01T10:00:00Z',
            'branch' => 'feature/v4.0-W4.B.2',
            'branch_semantics' => 'feature, version cycle v4.0',
            'ai_attribution' => 'human',
            'files_changed' => [$file],
        ];
    }
}
