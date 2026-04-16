<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Document;

final readonly class DocumentManifestItem
{
    public function __construct(
        public DocumentId $id,
        public DocumentMetadata $metadata,
        public string $path,
    ) {}
}
