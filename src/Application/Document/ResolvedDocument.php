<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Document;

final readonly class ResolvedDocument
{
    public function __construct(
        public DocumentId $id,
        public DocumentMetadata $metadata,
        public string $markdown,
        public string $path,
    ) {}
}
