<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

final readonly class LocalObjectStorage implements ObjectStorageInterface
{
    public function __construct(private StorageService $storageService)
    {
    }

    public function write(string $path, string $contents): StoredObject
    {
        $stored = $this->storageService->storeBytes($contents, $path);

        return new StoredObject($stored['storagePath'], $stored['sizeBytes']);
    }

    public function read(string $path): string
    {
        $contents = file_get_contents($this->storageService->getAbsolutePath($path));
        if (false === $contents) {
            throw new ObjectStorageException(sprintf('Failed to read object "%s".', $path));
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $stream = @fopen($this->storageService->getAbsolutePath($path), 'rb');
        if (false === $stream) {
            throw new ObjectStorageException(sprintf('Failed to open object "%s" for reading.', $path));
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        return is_file($this->storageService->getAbsolutePath($path));
    }

    public function delete(string $path): void
    {
        $absolutePath = $this->storageService->getAbsolutePath($path);
        if (is_file($absolutePath) && !@unlink($absolutePath)) {
            throw new ObjectStorageException(sprintf('Failed to delete object "%s".', $path));
        }
    }
}
