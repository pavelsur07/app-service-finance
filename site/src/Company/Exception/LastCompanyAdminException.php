<?php

declare(strict_types=1);

namespace App\Company\Exception;

/**
 * Изменение оставило бы компанию без делегированного администратора.
 *
 * Контроллеры проверяют это заранее, чтобы дать понятное сообщение, но между проверкой
 * и записью возможно конкурентное изменение. Окончательное слово — за проверкой в Action
 * под блокировкой строки компании, и отказ приходит этим исключением.
 */
final class LastCompanyAdminException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Company would be left without a delegated admin.', 0, $previous);
    }
}
