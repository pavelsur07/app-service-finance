<?php

declare(strict_types=1);

namespace App\Ingestion\Exception;

/**
 * Ответ источника нарушает контракт: не тот тип, нет обязательного поля.
 *
 * Отдельно от {@see ConnectorTransientException}, потому что ретраить такое
 * бессмысленно — повтор вернёт ту же поломанную структуру. И отдельно от
 * «пусто»: пустой список это валидный ответ, а отсутствующий ключ — нет.
 */
final class MalformedConnectorResponseException extends \RuntimeException
{
}
