<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Cash\DTO\CashflowCategoryUsage;

final class CashflowCategoryInUseException extends \RuntimeException
{
    private const ADVICE = 'Перенесите операции, платежи и правила на другую статью, затем удалите эту.';

    public static function withUsage(CashflowCategoryUsage $usage): self
    {
        return new self('Статью нельзя удалить. '.$usage->summary().' '.self::ADVICE);
    }

    public static function referenced(\Throwable $previous): self
    {
        return new self('Статью нельзя удалить: на неё ссылаются операции, платежи или правила. '.self::ADVICE, 0, $previous);
    }
}
