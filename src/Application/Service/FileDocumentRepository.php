<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Docs\Application\Document\DocumentId;
use Semitexa\Docs\Application\Document\DocumentManifestItem;
use Semitexa\Docs\Application\Document\DocumentMetadata;
use Semitexa\Docs\Application\Document\ResolvedDocument;

#[AsService]
final class FileDocumentRepository
{
    #[InjectAsReadonly]
    protected DocumentFrontMatterParser $frontMatterParser;

    public function find(DocumentId $id, string $locale = 'en'): ?ResolvedDocument
    {
        foreach ($this->candidatePaths($id, $locale) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            return $this->loadFromPath($id, $candidate);
        }

        return null;
    }

    /**
     * @return list<DocumentManifestItem>
     */
    public function all(string $locale = 'en'): array
    {
        $root = $this->docsRoot() . '/' . $locale;
        if (!is_dir($root)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile() || $fileInfo->getExtension() !== 'md') {
                continue;
            }

            $relativePath = str_replace($root . '/', '', $fileInfo->getPathname());
            $normalized = str_replace('.md', '', $relativePath);
            $id = DocumentId::fromString(str_replace(DIRECTORY_SEPARATOR, '/', $normalized));
            $document = $this->loadFromPath($id, $fileInfo->getPathname());
            $items[] = new DocumentManifestItem($document->id, $document->metadata, $document->path);
        }

        usort(
            $items,
            static fn (DocumentManifestItem $left, DocumentManifestItem $right): int =>
                [$left->id->section, $left->metadata->order, $left->id->slug]
                    <=>
                [$right->id->section, $right->metadata->order, $right->id->slug],
        );

        return $items;
    }

    private function loadFromPath(DocumentId $id, string $path): ResolvedDocument
    {
        $contents = (string) file_get_contents($path);
        $parsed = $this->frontMatterParser->parse($contents, $path);
        $meta = $parsed['meta'];

        $this->assertRequired($meta, ['id', 'section', 'slug', 'title', 'summary', 'order', 'locale', 'status'], $path);

        if ($meta['id'] !== $id->toString()) {
            throw new \RuntimeException(sprintf(
                'Document id mismatch for "%s": front matter says "%s", expected "%s".',
                $path,
                $meta['id'],
                $id->toString(),
            ));
        }

        return new ResolvedDocument(
            id: $id,
            metadata: new DocumentMetadata(
                title: (string) $meta['title'],
                summary: (string) $meta['summary'],
                order: (int) $meta['order'],
                locale: (string) $meta['locale'],
                status: (string) $meta['status'],
                aliases: $this->normalizeList($meta['aliases'] ?? []),
                keywords: $this->normalizeList($meta['keywords'] ?? []),
                demoPreview: is_string($meta['demo_preview'] ?? null) ? $meta['demo_preview'] : null,
                relatedDocuments: $this->normalizeList($meta['related_documents'] ?? []),
                relatedExamples: $this->normalizeList($meta['related_examples'] ?? []),
                recommendedRuntimePanels: $this->normalizeList($meta['recommended_runtime_panels'] ?? []),
                sourceExamples: $this->normalizeList($meta['source_examples'] ?? []),
                callouts: $this->normalizeList($meta['callouts'] ?? []),
            ),
            markdown: $parsed['body'],
            path: $path,
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $required
     */
    private function assertRequired(array $meta, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $meta)) {
                throw new \RuntimeException(sprintf('Missing required front matter key "%s" in "%s".', $key, $path));
            }
        }
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): ?string => is_string($item) && $item !== '' ? $item : null,
                $value,
            ),
            static fn (?string $item): bool => $item !== null,
        ));
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(DocumentId $id, string $locale): array
    {
        $relative = $id->section . '/' . $id->slug . '.md';

        if ($locale === 'en') {
            return [$this->docsRoot() . '/en/' . $relative];
        }

        return [
            $this->docsRoot() . '/' . $locale . '/' . $relative,
            $this->docsRoot() . '/en/' . $relative,
        ];
    }

    private function docsRoot(): string
    {
        return dirname(__DIR__, 3) . '/docs';
    }
}
