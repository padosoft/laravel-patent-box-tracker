<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Per `feedback_packages_standalone_agnostic` and PLAN-W4 §6.5 — the
 * package source MUST NOT reference any AskMyDocs-specific symbol or
 * table name. The package is consumed by AskMyDocs but never depends
 * on it; community adoption depends on this invariant.
 *
 * Note: this is a plain PHPUnit\Framework\TestCase (no Testbench bootstrap)
 * because the assertion is purely textual / file-system-based and does
 * not need the Laravel container.
 */
final class StandaloneAgnosticTest extends TestCase
{
    /**
     * Forbidden substrings that, if present in any PHP file under src/,
     * indicate a leak from AskMyDocs into this standalone package.
     *
     * @return list<string>
     */
    private static function forbiddenNeedles(): array
    {
        return [
            'KnowledgeDocument',
            'KbSearchService',
            'knowledge_documents',
            'knowledge_chunks',
            'kb_nodes',
            'kb_edges',
            'kb_canonical_audit',
            'lopadova/askmydocs',
            'App\\Models\\KnowledgeDocument',
        ];
    }

    public function test_src_directory_does_not_reference_askmydocs_symbols(): void
    {
        $srcPath = realpath(__DIR__.'/../../src');
        $this->assertNotFalse($srcPath, 'src/ directory must exist.');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcPath, FilesystemIterator::SKIP_DOTS),
        );

        $offenders = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach (self::forbiddenNeedles() as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s contains "%s"', $file->getPathname(), $needle);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Standalone-agnostic violation. The package must not reference AskMyDocs symbols.\n"
            .implode("\n", $offenders),
        );
    }

    public function test_classifier_directory_is_walked_and_clean(): void
    {
        // Self-documenting assertion: the recursive walk above already
        // covers everything under src/, but the Classifier + Models
        // directories landed in W4.B.2 — make the coverage explicit
        // so a future contributor doesn't accidentally add an
        // exemption that hides a leak.
        $classifierDir = realpath(__DIR__.'/../../src/Classifier');
        $modelsDir = realpath(__DIR__.'/../../src/Models');

        $this->assertNotFalse($classifierDir, 'src/Classifier/ must exist after W4.B.2.');
        $this->assertNotFalse($modelsDir, 'src/Models/ must exist after W4.B.2.');

        foreach ([$classifierDir, $modelsDir] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::forbiddenNeedles() as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        sprintf('%s leaks AskMyDocs symbol "%s".', $file->getPathname(), $needle),
                    );
                }
            }
        }
    }

    public function test_composer_json_does_not_require_askmydocs(): void
    {
        $composerPath = realpath(__DIR__.'/../../composer.json');
        $this->assertNotFalse($composerPath, 'composer.json must exist.');

        $raw = (string) file_get_contents($composerPath);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        $require = (array) ($decoded['require'] ?? []);
        $requireDev = (array) ($decoded['require-dev'] ?? []);

        $this->assertArrayNotHasKey(
            'lopadova/askmydocs',
            $require,
            'composer.json must not require lopadova/askmydocs.',
        );
        $this->assertArrayNotHasKey(
            'lopadova/askmydocs',
            $requireDev,
            'composer.json must not require lopadova/askmydocs in require-dev.',
        );
        $this->assertArrayNotHasKey(
            'padosoft/askmydocs-pro',
            $require,
            'composer.json must not require padosoft/askmydocs-pro.',
        );
    }
}
