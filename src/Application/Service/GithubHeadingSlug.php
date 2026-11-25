<?php

declare(strict_types=1);

namespace Semitexa\Docs\Application\Service;

use League\CommonMark\Normalizer\TextNormalizerInterface;

/**
 * A heading's anchor as GitHub writes it, so a page's `#section` links work
 * on the docs site as they do on GitHub: lowercase, punctuation dropped
 * (underscores and hyphens kept), each space a hyphen. "Delivery:
 * `webhook:work`" is `delivery-webhookwork`, `SSE_MAX_CONN_GLOBAL` is
 * `sse_max_conn_global`. CommonMark's own SlugNormalizer drops underscores.
 * Repeated headings get -1, -2 from the unique wrapper the environment adds.
 */
final class GithubHeadingSlug implements TextNormalizerInterface
{
    /** @param array<string, mixed> $context */
    public function normalize(string $text, array $context = []): string
    {
        // Not trimmed: a space a character reference decodes to stays, as on GitHub
        // ("## &#x20;Configuration" is "-configuration"); the parser already strips the rest.
        $slug = mb_strtolower($text, 'UTF-8');
        $slug = (string) preg_replace('/[^\p{L}\p{Nd}\p{Nl}\p{M}\s_-]+/u', '', $slug);

        return (string) preg_replace('/\s/u', '-', $slug);
    }
}
