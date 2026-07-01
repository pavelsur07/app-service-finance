<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

interface ObjectStorageInterface
{
    public function write(string $path, string $contents): StoredObject;

    public function read(string $path): string;

    /**
     * Открыть объект на чтение потоком (для больших файлов и стриминга в HTTP-ответ).
     *
     * @return resource
     */
    public function readStream(string $path);

    public function exists(string $path): bool;

    /**
     * Удалить объект. Идемпотентно: удаление отсутствующего объекта — не ошибка.
     */
    public function delete(string $path): void;
}
