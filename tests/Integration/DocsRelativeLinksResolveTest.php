<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every relative .md link in the published docs points at a page that exists.
 *
 * DocumentHtmlRenderer turns `[x](../dir/page.md)` into the page's URL, so a
 * link to a file that is not there becomes a 404 on semitexa.com/docs. Three
 * were live on 2026-10-16 (orm-guide -> MODULE_STRUCTURE.md, skill-contract ->
 * skin-refinement.md, skin-generation -> llm-prompt.md), found by crawling the
 * site, not by any check.
 */
final class DocsRelativeLinksResolveTest extends TestCase
{
    #[Test]
    public function every_relative_markdown_link_resolves_to_a_file(): void
    {
        $root = dirname(__DIR__, 2) . '/docs/en';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $checked = 0;
        $broken = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            // Links inside fenced code are examples, not navigation.
            $text = (string) preg_replace('/^```.*?^```/ms', '', $text);
            preg_match_all('/\]\(([^)\s#]+\.md)(?:#[^)]*)?\)/', $text, $m);
            foreach ($m[1] as $target) {
                if (preg_match('#^(?:[a-z]+:|/)#i', $target) === 1) {
                    continue;
                }
                $checked++;
                if (!is_file($file->getPath() . '/' . $target)) {
                    $broken[] = substr($file->getPathname(), strlen($root) + 1) . ' -> ' . $target;
                }
            }
        }

        self::assertGreaterThan(50, $checked, 'the scan must actually find the docs and their links');
        self::assertSame([], $broken, "relative links that 404 on the site:\n" . implode("\n", $broken));
    }
}
