<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Shared\Domain\Exception\UserFacingException;

/**
 * В денежный счёт уже идёт импорт выписки (файл, 1С или банк): второй импорт того же счёта не
 * запускается параллельно. Сообщение пользовательское — помечено UserFacingException.
 */
final class CashImportAccountBusyException extends \DomainException implements UserFacingException
{
}
