<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cross-cutting regression guard for durable developer documentation.
 *
 * The framework's hardening epic retired several architectural shapes
 * (legacy payload attribute, legacy public-endpoint marker, the racy
 * seen()+markSeen() webhook flow, host-side `vendor/bin/phpunit`).
 * Without this guard, doc updates can silently regress to old shapes
 * even after the source code, templates, and generators have moved on
 * (cycle 25 closed the same gate for templates and plan builders).
 *
 * Scope:
 *   Every Markdown file under packages/semitexa-docs/docs (recursive),
 *   except for files in {@see ALLOWED_FILES}.
 *
 * Detection model:
 *   Two-stage filter so anti-pattern documentation can show forbidden
 *   shapes without false-positiving:
 *     1. Forbidden patterns are checked ONLY inside ``` ... ``` blocks —
 *        prose mentions of the same names (e.g. "the retired
 *        #[PublicEndpoint] attribute" inline with backticks) are allowed.
 *     2. Inside a fenced block, a line is allowed if a "BAD" / "WRONG" /
 *        "AVOID" / "DON'T" / "NEVER" anti-pattern marker has appeared in
 *        any preceding line of the SAME fenced block. The same-block
 *        scope means the marker on a previous example does not silence a
 *        new "GOOD" block that accidentally repeats the bad shape.
 *
 * Migration guide exception:
 *   `migration/post-hardening.md` deliberately quotes "Before" examples
 *   in fenced blocks for context. The guide is the SOLE allowed location
 *   for retired-shape usage examples without per-block "BAD" markers, so
 *   it is whitelisted via {@see ALLOWED_FILES}.
 */
final class DocsForbiddenPatternRegressionTest extends TestCase
{
    private const DOCS_ROOT = __DIR__ . '/../../docs';

    /** Files where retired patterns are intentional and allowed everywhere (prose AND fenced blocks). */
    private const ALLOWED_FILES = [
        'en/migration/post-hardening.md',
    ];

    /**
     * Forbidden patterns that must not appear inside fenced code blocks.
     *
     * Each pattern is a regex; "label" is what the failure message reports.
     * Patterns are intentionally narrow:
     *   - `#[AsPayload(` matches the open-paren usage form, not `#[AsPayloadHandler]` / `#[AsPayloadPart(` (which are still valid).
     *   - `#[PublicEndpoint]` matches the standalone retired attribute usage.
     *   - `vendor/bin/phpunit` matches the host-side direct invocation.
     *   - `->markSeen(` matches the racy method call (only flagged if `WebhookReplayStore` appears in the same file).
     *   - `(?:^|\W)[Cc]ycle[ -]\d+` matches implementation-cycle markers.
     */
    private const FORBIDDEN_FENCED_PATTERNS = [
        ['regex' => '/#\[\s*AsPayload\s*\(/', 'label' => 'retired #[AsPayload(...)] attribute — use #[AsPublicPayload] / #[AsProtectedPayload] / #[AsServicePayload]'],
        ['regex' => '/#\[\s*PublicEndpoint\s*\]/', 'label' => 'retired #[PublicEndpoint] attribute — use #[AsPublicPayload]'],
        ['regex' => '/vendor\/bin\/phpunit/', 'label' => 'host-side vendor/bin/phpunit invocation — use bin/semitexa test:run'],
        ['regex' => '/(?:^|\W)[Cc]ycle[ -]\d+/', 'label' => 'cycle-N implementation marker — durable docs must not reference cycle numbers'],
    ];

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function docsFiles(): iterable
    {
        $root = realpath(self::DOCS_ROOT);
        self::assertNotFalse($root, 'docs root resolvable');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        foreach ($files as $relative => $absolute) {
            yield $relative => [$relative, $absolute];
        }
    }

    #[Test]
    #[DataProvider('docsFiles')]
    public function doc_does_not_contain_forbidden_patterns_inside_fenced_code(string $relative, string $absolute): void
    {
        if (in_array($relative, self::ALLOWED_FILES, true)) {
            // The migration guide is the canonical place for "before" code
            // examples — explicitly skipped here so the guide can teach the
            // delta without tripping its own gate.
            self::assertTrue(true, "Allowed file: {$relative}");
            return;
        }

        $content = (string) file_get_contents($absolute);
        $violations = self::scanFencedCodeBlocks($content);

        if ($violations === []) {
            self::assertTrue(true);
            return;
        }

        $messages = [];
        foreach ($violations as $hit) {
            $messages[] = sprintf(
                "  line %d: %s\n    %s",
                $hit['line'],
                $hit['label'],
                trim($hit['matchLine']),
            );
        }

        self::fail(sprintf(
            "Doc %s contains %d forbidden-pattern occurrence(s) inside fenced code:\n%s",
            $relative,
            count($violations),
            implode("\n", $messages),
        ));
    }

    /**
     * Walk the document line-by-line, tracking whether we are inside a
     * ``` block, and flag forbidden patterns only inside such blocks
     * UNLESS the current block already named the pattern as a "BAD" /
     * "WRONG" / "AVOID" / "DON'T" / "NEVER" example.
     *
     * Webhook race-window detection additionally requires `WebhookReplayStore`
     * to appear somewhere in the file (so non-webhook prose / examples
     * incidentally containing markSeen() are not false positives).
     *
     * @return list<array{line: int, label: string, matchLine: string}>
     */
    private static function scanFencedCodeBlocks(string $content): array
    {
        $lines = preg_split("/\r?\n/", $content);
        if ($lines === false) {
            return [];
        }

        $insideFence = false;
        $blockHasBadMarker = false;
        $hits = [];
        $hasReplayStore = str_contains($content, 'WebhookReplayStore');

        foreach ($lines as $idx => $line) {
            if (preg_match('/^\s{0,3}```/', $line) === 1) {
                $insideFence = !$insideFence;
                $blockHasBadMarker = false;
                continue;
            }
            if (!$insideFence) {
                continue;
            }

            // Track per-block anti-pattern markers. Once a BAD / WRONG /
            // AVOID / DON'T / NEVER appears in the block, subsequent lines
            // in the SAME fenced block are treated as anti-pattern context.
            // The marker resets when the block closes.
            if (preg_match('/\b(?:BAD|WRONG|AVOID|DON\x27?T|NEVER)\b/i', $line) === 1) {
                $blockHasBadMarker = true;
            }

            if ($blockHasBadMarker) {
                continue;
            }

            foreach (self::FORBIDDEN_FENCED_PATTERNS as $rule) {
                if (preg_match($rule['regex'], $line) === 1) {
                    $hits[] = [
                        'line' => $idx + 1,
                        'label' => $rule['label'],
                        'matchLine' => $line,
                    ];
                }
            }
            if ($hasReplayStore && str_contains($line, '->markSeen(')) {
                $hits[] = [
                    'line' => $idx + 1,
                    'label' => 'racy seen()+markSeen() flow — use markIfFirstSeen() (atomic)',
                    'matchLine' => $line,
                ];
            }
        }

        return $hits;
    }

    #[Test]
    public function migration_guide_exists_and_is_indexed_under_en_migration(): void
    {
        $expected = realpath(self::DOCS_ROOT . '/en/migration/post-hardening.md');
        self::assertNotFalse(
            $expected,
            'post-hardening migration guide must exist at packages/semitexa-docs/docs/en/migration/post-hardening.md',
        );
        $content = (string) file_get_contents($expected);
        self::assertStringContainsString('id: migration/post-hardening', $content, 'migration guide front-matter id');
        self::assertStringContainsString('# Post-Hardening Migration Guide', $content, 'migration guide H1');
    }

    #[Test]
    public function allowed_files_list_only_includes_real_files(): void
    {
        // The allowance list is small and load-bearing; protect against typos
        // by asserting every entry resolves to an actual file under DOCS_ROOT.
        foreach (self::ALLOWED_FILES as $relative) {
            $absolute = realpath(self::DOCS_ROOT . '/' . $relative);
            self::assertNotFalse(
                $absolute,
                sprintf('Allowance entry %s does not resolve to an existing file — typo or stale entry', $relative),
            );
        }
    }
}
