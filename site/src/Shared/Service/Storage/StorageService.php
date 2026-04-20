<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StorageService
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    public function ensureDir(string $relativeDir): void
    {
        $relativeDir = trim($relativeDir, '/');
        $targetDir = $this->storageRoot.('' !== $relativeDir ? '/'.$relativeDir : '');

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

    /**
     * @return array{storagePath: string, fileHash: string, sizeBytes: int, mimeType: ?string}
     */
    public function storeBytes(string $bytes, string $relativePath): array
    {
        $relativePath = ltrim($relativePath, '/');

        // Защита от Path Traversal: любой сегмент '..' позволил бы выйти за storageRoot.
        // Split по '/' вместо str_contains, чтобы имена файлов вроде "..zip" не срабатывали как false-positive.
        foreach (explode('/', $relativePath) as $segment) {
            if ('..' === $segment) {
                throw new \InvalidArgumentException(sprintf('Path traversal segment detected in "%s".', $relativePath));
            }
        }

        $targetDir = dirname($relativePath);

        if ('.' !== $targetDir && '' !== $targetDir) {
            $this->ensureDir($targetDir);
        }

        $absolutePath = $this->storageRoot.'/'.$relativePath;

        if (false === file_put_contents($absolutePath, $bytes, \LOCK_EX)) {
            throw new \RuntimeException(sprintf('Failed to write storage file "%s".', $absolutePath));
        }

        $fileHash = hash('sha256', $bytes);
        $sizeBytes = strlen($bytes);

        $finfo = finfo_open(\FILEINFO_MIME_TYPE);
        $mimeType = false !== $finfo ? (finfo_buffer($finfo, $bytes) ?: null) : null;
        if (false !== $finfo) {
            finfo_close($finfo);
        }

        return [
            'storagePath' => $relativePath,
            'fileHash' => $fileHash,
            'sizeBytes' => $sizeBytes,
            'mimeType' => $mimeType,
        ];
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->storageRoot.'/'.ltrim($relativePath, '/');
    }
}
