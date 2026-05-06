<?php

declare(strict_types=1);

namespace Semitexa\Docs\Domain\Model;

final readonly class DocumentMetadata
{
    /**
     * @param list<string> $aliases
     * @param list<string> $keywords
     * @param list<string> $relatedDocuments
     * @param list<string> $relatedExamples
     * @param list<string> $recommendedRuntimePanels
     * @param list<string> $sourceExamples
     * @param list<string> $callouts
     */
    public function __construct(
        public string $title,
        public string $summary,
        public int $order,
        public string $locale = 'en',
        public string $status = 'canonical',
        public array $aliases = [],
        public array $keywords = [],
        public ?string $demoPreview = null,
        public array $relatedDocuments = [],
        public array $relatedExamples = [],
        public array $recommendedRuntimePanels = [],
        public array $sourceExamples = [],
        public array $callouts = [],
    ) {}
}
