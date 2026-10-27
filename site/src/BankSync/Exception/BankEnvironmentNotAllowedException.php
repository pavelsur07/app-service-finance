<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Вход в песочницу банка — только для администратора: тестовая интеграция настроена на
 * проде ради проверки и демонстрации банку, клиентам она недоступна.
 */
final class BankEnvironmentNotAllowedException extends \RuntimeException
{
    public function __construct(string $baseUrl)
    {
        parent::__construct(sprintf('Bank environment "%s" is not allowed for this user.', $baseUrl));
    }
}
