<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Docs\Application\Document\DocumentManifestItem;

#[AsService]
final class DocumentManifestBuilder
{
    #[InjectAsReadonly]
    protected FileDocumentRepository $repository;

    /**
     * @return array<string, list<DocumentManifestItem>>
     */
    public function buildBySection(string $locale = 'en'): array
    {
        $grouped = [];

        foreach ($this->repository->all($locale) as $item) {
            $grouped[$item->id->section][] = $item;
        }

        ksort($grouped);

        return $grouped;
    }
}
