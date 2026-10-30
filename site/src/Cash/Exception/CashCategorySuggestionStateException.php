<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Cash\Enum\Transaction\CashCategorySuggestionStatus;

/**
 * Переход подсказки не из того статуса: подсказку уже приняли, отклонили или она устарела.
 */
final class CashCategorySuggestionStateException extends \RuntimeException
{
    public static function fromStatus(CashCategorySuggestionStatus $status): self
    {
        return new self(sprintf('Подсказка уже обработана (статус «%s»). Обновите страницу.', $status->value));
    }
}
