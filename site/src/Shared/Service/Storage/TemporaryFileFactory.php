<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

/**
 * Выдаёт временный файл с нужным расширением и гарантированно убирает его за собой —
 * даже если потребитель бросил исключение.
 *
 * Расширение обязательно: и OpenSpout, и PhpSpreadsheet выбирают формат по пути,
 * а не по содержимому.
 */
final class TemporaryFileFactory
{
    /**
     * @template T
     *
     * @param callable(string $path): T $consumer
     *
     * @return T
     */
    public function withXlsxPath(callable $consumer): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'xls-convert-');
        if (false === $path) {
            throw new ObjectStorageException('Failed to allocate a temporary file.');
        }

        $pathWithExtension = $path.'.xlsx';
        if (!@rename($path, $pathWithExtension)) {
            @unlink($path);

            throw new ObjectStorageException('Failed to prepare a temporary file for xls conversion.');
        }

        try {
            return $consumer($pathWithExtension);
        } finally {
            @unlink($pathWithExtension);
        }
    }
}
