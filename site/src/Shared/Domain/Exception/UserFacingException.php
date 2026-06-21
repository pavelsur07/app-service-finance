<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Маркер: сообщение исключения безопасно показывать конечному пользователю.
 * Исключения БЕЗ этого маркера не должны раскрывать getMessage() наружу (логируем, отвечаем обобщённо).
 */
interface UserFacingException extends \Throwable
{
}
