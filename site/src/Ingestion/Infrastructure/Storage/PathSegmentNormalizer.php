<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use Webmozart\Assert\Assert;

final class PathSegmentNormalizer
{
    public function normalize(string $segment): string
    {
        $segment = trim($segment);
        Assert::notEmpty($segment);

        $normalized = preg_replace('/[^A-Za-z0-9._=-]+/', '-', $segment);
        if (null === $normalized) {
            throw new \InvalidArgumentException('Failed to normalize storage path segment.');
        }

        $normalized = trim($normalized, '-.');
        Assert::notEmpty($normalized);

        return $normalized;
    }
}
