<?php

declare(strict_types=1);

namespace App\Company\Exception;

/**
 * Предложенное название применить нельзя: правила больше не уверены, название уже задано
 * вручную, контрагент в архиве или изменён параллельно. Сообщение — для пользователя.
 */
final class CounterpartyDisplayNameNotApplicableException extends \RuntimeException
{
}
