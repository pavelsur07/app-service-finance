<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

interface ObjectStorageInterface
{
    public function write(string $path, string $contents): StoredObject;

    public function read(string $path): string;

    public function exists(string $path): bool;
}
