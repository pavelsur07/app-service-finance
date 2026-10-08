<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

/**
 * Текст исключения не содержит путь объекта: GlitchTip группирует события по
 * тексту, и путь в нём дробил один сбой хранилища на issue по каждой записи.
 * Путь — в свойстве $path и в контексте лога LoggingObjectStorage.
 */
final class ObjectStorageException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
