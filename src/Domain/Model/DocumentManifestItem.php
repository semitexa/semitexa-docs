<?php

declare(strict_types=1);

namespace Semitexa\Docs\Domain\Model;

final readonly class DocumentManifestItem
{
    public function __construct(
        public DocumentId $id,
        public DocumentMetadata $metadata,
        public string $path,
    ) {}
}
