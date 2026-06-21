<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Shared\Domain\Exception\UserFacingException;

/**
 * Операция попадает в закрытый финансовый период компании.
 * Сообщение пользовательское — помечено UserFacingException.
 */
final class FinancePeriodLockedException extends \DomainException implements UserFacingException
{
}
