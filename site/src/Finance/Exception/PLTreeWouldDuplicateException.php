<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * Импорт создал бы корневые статьи рядом с деревом компании, которое источник почти не опознал, —
 * второе дерево ОПиУ. Наследует \DomainException: экран импорта и команда показывают его как
 * любой другой отказ импорта.
 */
final class PLTreeWouldDuplicateException extends \DomainException
{
    public static function roots(int $recognized, int $total, bool $template): self
    {
        return new self(\sprintf(
            '%s опознал %d из %d корневых статей компании: новые корневые статьи встали бы вторым деревом рядом с вашим. %s',
            $template ? 'Шаблон' : 'Источник',
            $recognized,
            $total,
            $template
                ? 'Сопоставьте свои статьи с шаблоном («Сопоставить с шаблоном» на странице импорта) и повторите.'
                : 'Присвойте своим статьям те же коды, что у статей источника, и повторите.',
        ));
    }
}
