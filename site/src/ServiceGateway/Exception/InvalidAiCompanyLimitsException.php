<?php

declare(strict_types=1);

namespace App\ServiceGateway\Exception;

/**
 * Неверный лимит расходов ИИ или глубина выгрузки; сообщение показывается администратору.
 */
final class InvalidAiCompanyLimitsException extends \RuntimeException
{
}
