<?php

declare(strict_types=1);

namespace Semitexa\Docs\Domain\Model;

final readonly class ResolvedDocument
{
    public function __construct(
        public DocumentId $id,
        public DocumentMetadata $metadata,
        public string $markdown,
        public string $path,
    ) {}
}
