<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Document;

final readonly class DocumentId
{
    public function __construct(
        public string $section,
        public string $slug,
    ) {}

    public static function fromString(string $value): self
    {
        $normalized = trim($value, '/');
        $parts = explode('/', $normalized, 3);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(sprintf(
                'Invalid document id "%s". Expected "<section>/<slug>".',
                $value,
            ));
        }

        return new self($parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return $this->section . '/' . $this->slug;
    }
}
