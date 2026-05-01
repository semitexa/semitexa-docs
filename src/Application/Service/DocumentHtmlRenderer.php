<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Docs\Domain\Model\RenderedDocument;
use Semitexa\Docs\Domain\Model\ResolvedDocument;

#[AsService]
final class DocumentHtmlRenderer
{
    public function renderMarkdown(ResolvedDocument $document): RenderedDocument
    {
        return new RenderedDocument($document, 'markdown', $document->markdown);
    }

    public function renderHtml(ResolvedDocument $document): RenderedDocument
    {
        $html = (string) $this->converter()->convert($document->markdown);

        return new RenderedDocument(
            document: $document,
            format: 'html',
            content: sprintf(
                "<article class=\"sx-docs-fragment\" data-doc-id=\"%s\" data-doc-locale=\"%s\">\n%s\n</article>",
                htmlspecialchars($document->id->toString(), ENT_QUOTES),
                htmlspecialchars($document->metadata->locale, ENT_QUOTES),
                trim($html),
            ),
        );
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'renderer' => [
                'soft_break' => "\n",
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return new MarkdownConverter($environment);
    }
}
