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
    /**
     * Headings carry the anchor GitHub gives them as their id, so a page's
     * #section links work on the site too. Before, no heading had an id and
     * the 58 in-page anchors in docs/en worked only on GitHub.
     */
    #[Test]
    public function headings_carry_the_anchor_github_gives_them(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('runtime', 'webhooks'),
            metadata: new DocumentMetadata('Webhooks', 'Anchors.', 10),
            markdown: <<<'MD'
## Configuration

### Delivery: `webhook:work`

See [Delivery](#delivery-webhookwork).

## SSE_MAX_CONN_GLOBAL

## Twice

## Twice

## &#x20;Leading space
MD,
            path: '/docs/webhooks.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        preg_match_all('/<h[1-6] id="([^"]*)"/', $content, $ids);
        self::assertSame(['configuration', 'delivery-webhookwork', 'sse_max_conn_global', 'twice', 'twice-1', '-leading-space'], $ids[1]);
        self::assertStringContainsString('<a href="#delivery-webhookwork">Delivery</a>', $content, 'the in-page link names that id');
        self::assertStringNotContainsString('heading-permalink', $content, 'and no permalink markup is added');
    }

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
    public function a_supplied_coordinate_that_is_not_a_number_is_a_typo_not_a_default(): void
    {
        // Auto-placing it would draw a diagram that disagrees with its source
        // while looking perfectly fine.
        $document = new ResolvedDocument(
            id: new DocumentId('architecture', 'bad-coordinate'),
            metadata: new DocumentMetadata('Bad coordinate', 'Bad coordinate.', 10),
            markdown: "```semitexa-diagram\nnode: api | API | Details | left | 0\n```\n",
            path: '/docs/bad-coordinate.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('class="language-semitexa-diagram"', $content);
        self::assertStringNotContainsString('class="sx-docs-diagram"', $content);
    }

    #[Test]
    public function an_omitted_coordinate_still_falls_back_to_auto_placement(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('architecture', 'auto-placement'),
            metadata: new DocumentMetadata('Auto', 'Auto.', 10),
            markdown: "```semitexa-diagram\nnode: api | API | Details\nnode: db | DB | Stores it\nedge: api -> db\n```\n",
            path: '/docs/auto-placement.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('data-node="api"', $content);
        self::assertStringContainsString('data-node="db"', $content);
    }

    #[Test]
    public function provenance_is_shown_only_when_it_names_a_real_release(): void
    {
        foreach (['', 'unverified', '2026.09.19', 'v2026.09.19.1020'] as $value) {
            $document = new ResolvedDocument(
                id: new DocumentId('cli', 'provenance'),
                metadata: new DocumentMetadata('P', 'P.', 10, verifiedAgainst: $value),
                markdown: '# Page',
                path: '/docs/provenance.md',
            );

            $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

            // Prove the page rendered before asserting what it does not
            // contain: empty output would satisfy the negative on its own.
            self::assertStringContainsString('<article class="sx-docs-fragment"', $content);
            self::assertStringContainsString('Page</h1>', $content);
            self::assertStringNotContainsString(
                'sx-docs-verified',
                $content,
                sprintf('%s must not be dressed up as verification.', var_export($value, true)),
            );
        }

        $valid = new ResolvedDocument(
            id: new DocumentId('cli', 'provenance'),
            metadata: new DocumentMetadata('P', 'P.', 10, verifiedAgainst: '2026.09.19.1020'),
            markdown: '# Page',
            path: '/docs/provenance.md',
        );

        self::assertStringContainsString(
            'data-verified-against="2026.09.19.1020"',
            (new DocumentHtmlRenderer())->renderHtml($valid)->content,
        );
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

    #[Test]
    public function a_link_to_another_page_points_at_that_page_not_its_file(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('di', 'readonly'),
            metadata: new DocumentMetadata('Readonly', 'Readonly services.', 10),
            markdown: <<<'MD'
See [mutable](mutable.md), [protected](../auth/protected.md#roles), [here](./factory.md),
[the repo](https://github.com/semitexa/semitexa-docs/blob/main/README.md), [anchor](#top),
[too deep](../../outside.md) and [nested](sub/dir/page.md).
MD,
            path: '/docs/di/readonly.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document)->content;

        self::assertStringContainsString('href="/docs/di/mutable"', $content);
        self::assertStringContainsString('href="/docs/auth/protected#roles"', $content);
        self::assertStringContainsString('href="/docs/di/factory"', $content);
        self::assertStringContainsString('href="https://github.com/semitexa/semitexa-docs/blob/main/README.md"', $content);
        self::assertStringContainsString('href="#top"', $content);
        // Not a <section>/<slug> page: left visibly broken rather than guessed.
        self::assertStringContainsString('href="../../outside.md"', $content);
        self::assertStringContainsString('href="sub/dir/page.md"', $content);
    }

    #[Test]
    public function a_page_on_another_host_links_to_the_docs_site(): void
    {
        $document = new ResolvedDocument(
            id: new DocumentId('di', 'readonly'),
            metadata: new DocumentMetadata('Readonly', 'Readonly services.', 10),
            markdown: 'See [services](services.md).',
            path: '/docs/di/readonly.md',
        );

        $content = (new DocumentHtmlRenderer())->renderHtml($document, 'https://semitexa.com/docs')->content;

        self::assertStringContainsString('href="https://semitexa.com/docs/di/services"', $content);
    }
}
