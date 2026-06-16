<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use Webmozart\Assert\Assert;

final readonly class StoredObject
{
    public function __construct(
        public string $path,
        public int $byteSize,
    ) {
        Assert::notEmpty($this->path);
        Assert::natural($this->byteSize);
    }
}
