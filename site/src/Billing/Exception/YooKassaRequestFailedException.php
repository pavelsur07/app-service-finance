<?php

declare(strict_types=1);

namespace App\Billing\Exception;

/**
 * ЮKassa отклонила запрос (4xx, кроме 429) или вернула нечитаемый ответ — повтор не поможет.
 */
final class YooKassaRequestFailedException extends \RuntimeException
{
    public function __construct(?int $statusCode, ?string $errorCode = null)
    {
        parent::__construct(sprintf('ЮKassa отклонила запрос (%s%s).', null === $statusCode ? 'нечитаемый ответ' : 'HTTP '.$statusCode, null === $errorCode ? '' : ', '.$errorCode));
    }
}
