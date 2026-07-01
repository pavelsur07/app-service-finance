<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

/**
 * Скачивает объект из хранилища во временный локальный файл, отдаёт его путь
 * в $consumer и гарантированно удаляет файл после — даже если $consumer бросил.
 *
 * Нужно там, где сторонним парсерам (PhpSpreadsheet, ZipArchive, fgetcsv)
 * требуется реальный путь на диске, а не поток — им нельзя передать "s3://".
 */
final readonly class TemporaryLocalFile
{
    public function __construct(private ObjectStorageInterface $storage)
    {
    }

    /**
     * Временный файл получает то же расширение, что и ключ объекта — некоторые
     * парсеры/ридеры (OpenSpout, PhpSpreadsheet) выбирают формат по расширению пути.
     *
     * @template T
     *
     * @param callable(string $localPath): T $consumer
     *
     * @return T
     */
    public function with(string $path, callable $consumer): mixed
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'objstore-');
        if (false === $tmpPath) {
            throw new ObjectStorageException('Failed to allocate a temporary file.');
        }

        $extension = pathinfo($path, \PATHINFO_EXTENSION);
        if ('' !== $extension) {
            $tmpWithExt = $tmpPath.'.'.$extension;
            if (!@rename($tmpPath, $tmpWithExt)) {
                @unlink($tmpPath);

                throw new ObjectStorageException(sprintf('Failed to prepare a temporary file for object "%s".', $path));
            }
            $tmpPath = $tmpWithExt;
        }

        try {
            $source = $this->storage->readStream($path);
            $target = @fopen($tmpPath, 'wb');
            if (false === $target) {
                throw new ObjectStorageException(sprintf('Failed to open temporary file for object "%s".', $path));
            }

            try {
                if (false === stream_copy_to_stream($source, $target)) {
                    throw new ObjectStorageException(sprintf('Failed to buffer object "%s" to a temporary file.', $path));
                }
            } finally {
                fclose($target);
                if (is_resource($source)) {
                    fclose($source);
                }
            }

            return $consumer($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }
}
