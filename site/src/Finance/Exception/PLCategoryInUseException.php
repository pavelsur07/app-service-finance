<?php

declare(strict_types=1);

namespace App\Finance\Exception;

use App\Finance\Application\DTO\PLCategoryUsage;

/**
 * Статью ОПиУ нельзя удалить: у неё есть вложенные статьи или на неё ссылаются другие записи.
 *
 * Проверку делает вызывающее действие заранее (PLCategoryUsageQuery), чтобы назвать причину.
 * Ссылка, добавленная между проверкой и удалением, приходит отказом FK и транслируется сюда же
 * вместо 500.
 */
final class PLCategoryInUseException extends \RuntimeException
{
    public static function withUsage(PLCategoryUsage $usage): self
    {
        return new self('Статью нельзя удалить: '.$usage->summary().'. '.$usage->advice());
    }

    public static function referenced(\Throwable $previous): self
    {
        return new self('Статью нельзя удалить: на неё ссылаются другие записи. Обновите страницу, чтобы увидеть причину.', 0, $previous);
    }
}
