<?php

declare(strict_types=1);

namespace App\Service\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StorageService
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    public function ensureDir(string $relativeDir): void
    {
        $relativeDir = trim($relativeDir, '/');
        $targetDir = $this->storageRoot.($relativeDir !== '' ? '/'.$relativeDir : '');

        if (is_dir($targetDir)) {
            return;
        }

        if (!mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Failed to create storage directory "%s".', $targetDir));
        }
    }

    /**
     * @return array{storagePath: string, fileHash: string, originalFilename: string}
     */
    public function storeUploadedFile(UploadedFile $file, string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');
        $targetDir = dirname($relativePath);

        if ('.' !== $targetDir && '' !== $targetDir) {
            $this->ensureDir($targetDir);
        }

        $absolutePath = $this->storageRoot.'/'.$relativePath;
        $fileHash = hash_file('sha256', $file->getPathname()) ?: '';
        $originalFilename = $file->getClientOriginalName();

        $file->move(dirname($absolutePath), basename($absolutePath));

        return [
            'storagePath' => $relativePath,
            'fileHash' => $fileHash,
            'originalFilename' => $originalFilename,
        ];
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->storageRoot.'/'.ltrim($relativePath, '/');
    }
}
