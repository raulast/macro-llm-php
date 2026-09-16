<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit;

use FilesystemIterator;
use MacroLLM\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * Guards the packaged skill documentation against API drift.
 *
 * Every concrete type under src/ (class or enum) must be mentioned by name in
 * the concatenated Markdown of skills/macro-llm-php/, so the packaged skill
 * never documents a surface that no longer exists and never silently omits one
 * that users can reach.
 */
final class SkillDriftTest extends TestCase
{
    /**
     * Types deliberately kept out of the skill documentation scope.
     *
     * MacroLLMFacade and MacroLLMServiceProvider are framework bootstrap glue
     * (Laravel auto-discovery entry points), not user-facing API. Everything
     * else must be traceable from the skill text.
     *
     * Abstract classes, interfaces and traits are already filtered out by the
     * reflection checks in publicClassShortNames(), so they never belong here.
     */
    private const EXCLUDED_SHORT_NAMES = [
        'MacroLLMFacade',
        'MacroLLMServiceProvider',
    ];

    // ── Skill documentation coverage ─────────────────────────────────────────

    #[DataProvider('publicClassNamesProvider')]
    public function test_public_class_is_mentioned_in_skills_text(string $className): void
    {
        $skillsDir = self::repoRoot() . '/skills/macro-llm-php';

        if (! is_dir($skillsDir)) {
            $this->markTestSkipped('skills/macro-llm-php/ not yet populated');
        }

        $skillsText = self::concatenateMarkdown($skillsDir);

        $this->assertStringContainsString(
            $className,
            $skillsText,
            "Class '{$className}' is not mentioned in skills/macro-llm-php/ — add it or update the exclusion list.",
        );
    }

    /**
     * One dataset per concrete type under src/, keyed by short class name.
     *
     * A dataset per class is what makes PHPUnit report every drifting class:
     * an assertion failure aborts the test method, so a single looping test
     * would only ever name the first offender.
     *
     * @return array<string, array{string}>
     */
    public static function publicClassNamesProvider(): array
    {
        $datasets = [];

        foreach (self::publicClassShortNames() as $shortName) {
            $datasets[$shortName] = [$shortName];
        }

        if ($datasets === []) {
            throw new RuntimeException(
                'No classes found under src/ — the skill drift check would pass vacuously.',
            );
        }

        return $datasets;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Repo root, derived from this file's location (tests/Unit/ -> repo root).
     */
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Short names of every concrete type under src/.
     *
     * Abstract classes, interfaces and traits are skipped; enums are kept.
     *
     * @return list<string>
     */
    private static function publicClassShortNames(): array
    {
        $srcDir = self::repoRoot() . '/src';
        $names = [];

        foreach (self::phpFilesIn($srcDir) as $file) {
            // src/ is PSR-4 mapped to MacroLLM\, so the FQCN follows the path.
            $relativePath = substr($file->getPathname(), strlen($srcDir) + 1, -4);
            $fqcn = 'MacroLLM\\' . str_replace('/', '\\', $relativePath);

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            $shortName = $reflection->getShortName();

            if (in_array($shortName, self::EXCLUDED_SHORT_NAMES, true)) {
                continue;
            }

            $names[] = $shortName;
        }

        sort($names);

        return $names;
    }

    /**
     * All Markdown files under a directory tree, recursively.
     *
     * @return list<SplFileInfo>
     */
    private static function markdownFilesIn(string $directory): array
    {
        return self::filesWithExtension($directory, 'md');
    }

    /**
     * All PHP files under a directory tree, recursively.
     *
     * @return list<SplFileInfo>
     */
    private static function phpFilesIn(string $directory): array
    {
        return self::filesWithExtension($directory, 'php');
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function filesWithExtension(string $directory, string $extension): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === $extension) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Full text content of every Markdown file under a directory tree.
     */
    private static function concatenateMarkdown(string $directory): string
    {
        $text = '';

        foreach (self::markdownFilesIn($directory) as $file) {
            $text .= (string) file_get_contents($file->getPathname());
        }

        return $text;
    }
}
