<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * Ввод администратора не прошёл проверку (даты, срок, сумма); сообщение — для показа.
 */
final class InvalidBillingInputException extends \RuntimeException
{
}
