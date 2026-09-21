<?php

declare(strict_types=1);

namespace Semitexa\Docs\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Docs\Application\Service\DocumentHtmlRenderer;
use Semitexa\Docs\Domain\Model\DocumentId;
use Semitexa\Docs\Domain\Model\DocumentMetadata;
use Semitexa\Docs\Domain\Model\ResolvedDocument;

final class DocumentHtmlRendererTest extends TestCase
{
    #[Test]
    public function renders_copyable_commands_with_an_expected_result(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('cli', 'command'),
            metadata: new DocumentMetadata('Command', 'Runnable command.', 10),
            markdown: <<<'MD'
```semitexa-command
bin/semitexa ai:orient --json
---
JSON envelope with artifact semitexa-dev.ai-orient/v1.
```
MD,
            path: '/docs/command.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('data-doc-command', $content);
        self::assertStringContainsString('data-code-block', $content);
        self::assertStringContainsString('data-copy-raw-source=', $content);
        self::assertStringContainsString('Run in the project root', $content);
        self::assertStringContainsString('<strong>Expected result</strong>', $content);
        self::assertStringContainsString('semitexa-dev.ai-orient/v1', $content);
        self::assertStringNotContainsString('language-semitexa-command', $content);
    }

    #[Test]
    public function keeps_incomplete_command_source_visible_for_diagnosis(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('cli', 'invalid-command'),
            metadata: new DocumentMetadata('Command', 'Invalid command.', 10),
            markdown: "```semitexa-command\nbin/semitexa ai:orient --json\n```\n",
            path: '/docs/invalid-command.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('language-semitexa-command', $content);
        self::assertStringNotContainsString('data-doc-command', $content);
    }

    #[Test]
    public function renders_accessible_diagram_blocks_as_inline_svg(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('architecture', 'request-flow'),
            metadata: new DocumentMetadata('Request flow', 'Request flow.', 10, verifiedAgainst: '2026.09.19.1020'),
            markdown: <<<'MD'
```semitexa-diagram
title: Request lifecycle
node: payload | Payload | Validates input | 0 | 0
node: handler | Handler | Runs the use case | 1 | 0
edge: payload -> handler | dispatch
```
MD,
            path: '/docs/request-flow.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('<figure class="sx-docs-diagram"', $content);
        self::assertStringContainsString('role="img"', $content);
        self::assertStringContainsString('<title id="sx-diagram-title-1">Request lifecycle</title>', $content);
        self::assertStringContainsString('data-node="payload"', $content);
        self::assertStringContainsString('marker-end="url(#sx-diagram-arrow-1)"', $content);
        self::assertStringContainsString('data-verified-against="2026.09.19.1020"', $content);
        self::assertStringNotContainsString('language-semitexa-diagram', $content);
    }

    #[Test]
    public function keeps_invalid_diagram_source_visible_for_diagnosis(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('architecture', 'invalid'),
            metadata: new DocumentMetadata('Invalid', 'Invalid diagram.', 10),
            markdown: "```semitexa-diagram\nnode: missing fields\n```\n",
            path: '/docs/invalid.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('class="language-semitexa-diagram"', $content);
        self::assertStringNotContainsString('class="sx-docs-diagram"', $content);
    }
}
