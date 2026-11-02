<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * Онлайн-оплата для этого кабинета или выбора недоступна; сообщение показывается клиенту.
 */
final class OnlinePaymentNotAvailableException extends \RuntimeException
{
}
