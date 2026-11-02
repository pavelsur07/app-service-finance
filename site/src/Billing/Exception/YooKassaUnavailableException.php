<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * ЮKassa временно недоступна (сеть, таймаут, 429, 5xx) — запрос можно повторить с тем же ключом.
 */
final class YooKassaUnavailableException extends \RuntimeException
{
    public function __construct(?int $statusCode)
    {
        parent::__construct(sprintf('ЮKassa временно недоступна (%s).', null === $statusCode ? 'нет ответа' : 'HTTP '.$statusCode));
    }
}
