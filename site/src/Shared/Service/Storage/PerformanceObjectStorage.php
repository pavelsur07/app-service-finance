<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use App\Shared\Infrastructure\Performance\PerformanceProbe;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Shared\Infrastructure\Performance\PerformanceStage;

/**
 * Декоратор {@see ObjectStorageInterface}: время и байты операций хранилища для
 * диагностики M1 (storage_read / storage_write, backend = драйвер). Вне области замера
 * (php-fpm, прочие команды) и при выключенном флаге — прямой проброс. Пути не пишутся.
 */
final readonly class PerformanceObjectStorage implements ObjectStorageInterface
{
    public function __construct(
        private ObjectStorageInterface $inner,
        private PerformanceRecorder $recorder,
        private string $backend,
    ) {
    }

    public function write(string $path, string $contents): StoredObject
    {
        return $this->recorder->measure(PerformanceStage::StorageWrite, function (PerformanceProbe $probe) use ($path, $contents): StoredObject {
            $probe->rows(1)->bytes(\strlen($contents));

            return $this->inner->write($path, $contents);
        }, $this->backend);
    }

    public function read(string $path): string
    {
        return $this->recorder->measure(PerformanceStage::StorageRead, function (PerformanceProbe $probe) use ($path): string {
            $contents = $this->inner->read($path);
            $probe->rows(1)->bytes(\strlen($contents));

            return $contents;
        }, $this->backend);
    }

    public function readStream(string $path)
    {
        // Байты потока неизвестны до чтения вызывающим кодом: замеряется только открытие.
        return $this->recorder->measure(PerformanceStage::StorageRead, fn () => $this->inner->readStream($path), $this->backend);
    }

    public function exists(string $path): bool
    {
        return $this->recorder->measure(PerformanceStage::StorageRead, fn (): bool => $this->inner->exists($path), $this->backend);
    }

    public function delete(string $path): void
    {
        $this->recorder->measure(PerformanceStage::StorageWrite, fn () => $this->inner->delete($path), $this->backend);
    }
}
