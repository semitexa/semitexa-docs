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
        $html = $this->renderCommands($html);
        $html = $this->renderDiagrams($html);
        // Show provenance only when it is a real release. An empty value, or
        // the `unverified` sentinel a generated page carries when the release
        // could not be resolved, must not be dressed up as verification.
        $verifiedAgainst = $document->metadata->verifiedAgainst;
        $isRelease = preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $verifiedAgainst) === 1;
        $verification = !$isRelease ? '' : sprintf(
            '<p class="sx-docs-verified" data-verified-against="%s"><strong>Verified against</strong> Semitexa Ultimate <code>%s</code></p>' . "\n",
            $this->escape($verifiedAgainst),
            $this->escape($verifiedAgainst),
        );

        return new RenderedDocument(
            document: $document,
            format: 'html',
            content: sprintf(
                "<article class=\"sx-docs-fragment\" data-doc-id=\"%s\" data-doc-locale=\"%s\">\n%s\n</article>",
                htmlspecialchars($document->id->toString(), ENT_QUOTES),
                htmlspecialchars($document->metadata->locale, ENT_QUOTES),
                $verification . trim($html),
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

    private function renderCommands(string $html): string
    {
        $index = 0;

        return preg_replace_callback(
            '#<pre><code class="language-semitexa-command">(.*?)</code></pre>#s',
            function (array $match) use (&$index): string {
                $source = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts = preg_split('/^---\h*$/m', trim($source), 2);
                if ($parts === false || count($parts) !== 2) {
                    return $match[0];
                }

                $command = trim($parts[0]);
                $expected = trim($parts[1]);
                if ($command === '' || $expected === '') {
                    return $match[0];
                }

                $position = ++$index;
                $key = substr(hash('sha256', $command), 0, 12) . '-' . $position;
                $sourceId = 'docs-command-source-' . $key;
                $rawId = 'docs-command-raw-' . $key;

                return sprintf(
                    '<div class="code-block sx-docs-command" data-code-block data-doc-command>'
                    . '<div class="code-block__panel code-block__panel--active">'
                    . '<div class="code-block__header"><div class="code-block__file">'
                    . '<span class="code-block__label">Terminal</span>'
                    . '<span class="code-block__file-note">Run in the project root</span></div>'
                    . '<button class="code-block__copy" type="button" title="Copy command to clipboard" data-copy-source="%s" data-copy-raw-source="%s">Copy</button></div>'
                    . '<pre class="code-block__pre"><code id="%s" class="code-block__code language-bash">%s</code></pre>'
                    . '<textarea id="%s" class="code-block__raw-source" tabindex="-1" aria-hidden="true">%s</textarea>'
                    . '</div><div class="sx-docs-command__result"><strong>Expected result</strong>'
                    . '<pre><code>%s</code></pre></div></div>',
                    $sourceId,
                    $rawId,
                    $sourceId,
                    $this->escape($command),
                    $rawId,
                    $this->escape($command),
                    $this->escape($expected),
                );
            },
            $html,
        ) ?? $html;
    }

    private function renderDiagrams(string $html): string
    {
        $index = 0;

        return preg_replace_callback(
            '#<pre><code class="language-semitexa-diagram">(.*?)</code></pre>#s',
            function (array $match) use (&$index): string {
                $source = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $diagram = $this->parseDiagram($source);
                if ($diagram === null) {
                    return $match[0];
                }

                return $this->renderDiagramSvg($diagram, ++$index);
            },
            $html,
        ) ?? $html;
    }

    /**
     * @return array{
     *   title: string,
     *   nodes: array<string, array{id: string, label: string, description: string, column: int, row: int}>,
     *   edges: list<array{from: string, to: string, label: string}>
     * }|null
     */
    private function parseDiagram(string $source): ?array
    {
        $title = 'Architecture flow';
        $nodes = [];
        $edges = [];
        $nextColumn = 0;

        foreach (preg_split('/\R/', trim($source)) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$type, $value] = array_pad(explode(':', $line, 2), 2, '');
            $type = strtolower(trim($type));
            $value = trim($value);

            if ($type === 'title' && $value !== '') {
                $title = $value;
                continue;
            }

            if ($type === 'node') {
                $parts = array_map('trim', explode('|', $value));
                if (count($parts) < 3 || preg_match('/^[a-z][a-z0-9-]*$/', $parts[0]) !== 1) {
                    return null;
                }

                // A supplied coordinate that is not a number is a typo. Falling
                // back to auto-placement would draw a diagram that quietly
                // disagrees with its own source, so reject it instead.
                $column = $this->coordinate($parts[3] ?? null, $nextColumn);
                $row = $this->coordinate($parts[4] ?? null, 0);
                if ($column === null || $row === null) {
                    return null;
                }
                $nodes[$parts[0]] = [
                    'id' => $parts[0],
                    'label' => $parts[1],
                    'description' => $parts[2],
                    'column' => $column,
                    'row' => $row,
                ];
                $nextColumn = max($nextColumn, $column + 1);
                continue;
            }

            if ($type === 'edge' && preg_match('/^([a-z][a-z0-9-]*)\s*->\s*([a-z][a-z0-9-]*)(?:\s*\|\s*(.*))?$/', $value, $edge) === 1) {
                $edges[] = [
                    'from' => $edge[1],
                    'to' => $edge[2],
                    'label' => trim($edge[3] ?? ''),
                ];
                continue;
            }

            return null;
        }

        if ($nodes === []) {
            return null;
        }

        foreach ($edges as $edge) {
            if (!isset($nodes[$edge['from']], $nodes[$edge['to']])) {
                return null;
            }
        }

        return ['title' => $title, 'nodes' => $nodes, 'edges' => $edges];
    }

    /** Null means "supplied and unusable"; the default means "not supplied". */
    private function coordinate(?string $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param array{
     *   title: string,
     *   nodes: array<string, array{id: string, label: string, description: string, column: int, row: int}>,
     *   edges: list<array{from: string, to: string, label: string}>
     * } $diagram
     */
    private function renderDiagramSvg(array $diagram, int $index): string
    {
        $nodeWidth = 190;
        $nodeHeight = 92;
        $gapX = 72;
        $gapY = 66;
        $padding = 34;
        $maxColumn = max(array_column($diagram['nodes'], 'column'));
        $maxRow = max(array_column($diagram['nodes'], 'row'));
        $width = ($padding * 2) + (($maxColumn + 1) * $nodeWidth) + ($maxColumn * $gapX);
        $height = ($padding * 2) + (($maxRow + 1) * $nodeHeight) + ($maxRow * $gapY);
        $markerId = 'sx-diagram-arrow-' . $index;
        $titleId = 'sx-diagram-title-' . $index;
        $descriptionId = 'sx-diagram-description-' . $index;
        $description = $diagram['title'] . '. ' . implode('. ', array_map(
            static fn (array $node): string => $node['label'] . ': ' . $node['description'],
            array_values($diagram['nodes']),
        ));

        $svg = sprintf(
            '<figure class="sx-docs-diagram" data-diagram="flow"><svg viewBox="0 0 %d %d" width="100%%" role="img" aria-labelledby="%s %s" xmlns="http://www.w3.org/2000/svg"><title id="%s">%s</title><desc id="%s">%s</desc><defs><marker id="%s" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="var(--demo-accent-dark, #a94f34)"/></marker></defs>',
            $width,
            $height,
            $titleId,
            $descriptionId,
            $titleId,
            $this->escape($diagram['title']),
            $descriptionId,
            $this->escape($description),
            $markerId,
        );

        foreach ($diagram['edges'] as $edge) {
            $from = $diagram['nodes'][$edge['from']];
            $to = $diagram['nodes'][$edge['to']];
            [$x1, $y1, $x2, $y2] = $this->edgeCoordinates($from, $to, $nodeWidth, $nodeHeight, $gapX, $gapY, $padding);
            $svg .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="var(--demo-accent-dark, #a94f34)" stroke-width="2.5" marker-end="url(#%s)"/>',
                $x1,
                $y1,
                $x2,
                $y2,
                $markerId,
            );

            if ($edge['label'] !== '') {
                $svg .= sprintf(
                    '<text x="%d" y="%d" text-anchor="middle" fill="var(--demo-text-muted, #617078)" stroke="var(--demo-bg-card, #fffaf4)" stroke-width="5" paint-order="stroke" font-family="ui-sans-serif, system-ui, sans-serif" font-size="11">%s</text>',
                    (int) (($x1 + $x2) / 2),
                    (int) (($y1 + $y2) / 2) - 7,
                    $this->escape($edge['label']),
                );
            }
        }

        foreach ($diagram['nodes'] as $node) {
            $x = $padding + ($node['column'] * ($nodeWidth + $gapX));
            $y = $padding + ($node['row'] * ($nodeHeight + $gapY));
            $svg .= sprintf(
                '<g class="sx-docs-diagram__node" data-node="%s"><rect x="%d" y="%d" width="%d" height="%d" rx="16" fill="var(--demo-bg-card, #fffaf4)" stroke="var(--demo-border, #d9c9b8)" stroke-width="2"/><text x="%d" y="%d" fill="var(--demo-text, #24333c)" font-family="ui-sans-serif, system-ui, sans-serif" font-size="16" font-weight="700">%s</text>',
                $this->escape($node['id']),
                $x,
                $y,
                $nodeWidth,
                $nodeHeight,
                $x + 16,
                $y + 29,
                $this->escape($node['label']),
            );

            foreach ($this->wrapDescription($node['description']) as $lineIndex => $line) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" fill="var(--demo-text-muted, #617078)" font-family="ui-sans-serif, system-ui, sans-serif" font-size="12">%s</text>',
                    $x + 16,
                    $y + 53 + ($lineIndex * 17),
                    $this->escape($line),
                );
            }

            $svg .= '</g>';
        }

        return $svg . '</svg><figcaption><strong>' . $this->escape($diagram['title']) . '</strong></figcaption></figure>';
    }

    /**
     * @param array{id: string, label: string, description: string, column: int, row: int} $from
     * @param array{id: string, label: string, description: string, column: int, row: int} $to
     * @return array{int, int, int, int}
     */
    private function edgeCoordinates(array $from, array $to, int $nodeWidth, int $nodeHeight, int $gapX, int $gapY, int $padding): array
    {
        $fromX = $padding + ($from['column'] * ($nodeWidth + $gapX));
        $fromY = $padding + ($from['row'] * ($nodeHeight + $gapY));
        $toX = $padding + ($to['column'] * ($nodeWidth + $gapX));
        $toY = $padding + ($to['row'] * ($nodeHeight + $gapY));

        if (abs($toX - $fromX) >= abs($toY - $fromY)) {
            return $toX >= $fromX
                ? [$fromX + $nodeWidth, $fromY + (int) ($nodeHeight / 2), $toX, $toY + (int) ($nodeHeight / 2)]
                : [$fromX, $fromY + (int) ($nodeHeight / 2), $toX + $nodeWidth, $toY + (int) ($nodeHeight / 2)];
        }

        return $toY >= $fromY
            ? [$fromX + (int) ($nodeWidth / 2), $fromY + $nodeHeight, $toX + (int) ($nodeWidth / 2), $toY]
            : [$fromX + (int) ($nodeWidth / 2), $fromY, $toX + (int) ($nodeWidth / 2), $toY + $nodeHeight];
    }

    /**
     * @return list<string>
     */
    private function wrapDescription(string $description): array
    {
        $lines = explode("\n", wordwrap($description, 27, "\n", true));
        if (count($lines) <= 2) {
            return $lines;
        }

        return [$lines[0], rtrim($lines[1], ' .') . '…'];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
