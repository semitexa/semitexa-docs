<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Document;

final readonly class RenderedDocument
{
    public function __construct(
        public ResolvedDocument $document,
        public string $format,
        public string $content,
    ) {}
}
