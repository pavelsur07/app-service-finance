<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * Статьи маркетплейса к дереву ОПиУ не добавлены — ничего не записано. Наследует \DomainException:
 * экран показывает его как отказ импорта.
 */
final class PLTreeExtensionException extends \DomainException
{
    public static function notUniversal(): self
    {
        return new self('Статьи маркетплейса добавляются к дереву профиля «Базовый»; в профиле «Маркетплейсы и e-com» они уже есть.');
    }

    public static function notUniversalTree(): self
    {
        return new self('Дерево ОПиУ компании не из шаблона «Базовый» (нет «Валовой прибыли» и «Выручки» шаблона) — статьи маркетплейса к нему не добавляются. Сопоставьте статьи с шаблоном или добавьте нужные вручную.');
    }

    /**
     * @param list<string> $pairs
     */
    public static function similarLines(array $pairs): self
    {
        return new self(\sprintf('У вас уже есть статьи, похожие на статьи маркетплейса: %s. Сопоставьте их с шаблоном или переименуйте, если это не они, и повторите — иначе итоги сослались бы на несозданные статьи.', implode('; ', $pairs)));
    }

    public static function misplaced(string $name, string $code): self
    {
        return new self(\sprintf('Статья «%s» (код %s) стоит не на месте шаблона: после дополнения она посчиталась бы в итогах дважды. Перенесите её на место шаблона или уберите код и повторите.', $name, $code));
    }

    public static function concurrentChange(string $name): self
    {
        return new self(\sprintf('Формулу «%s» только что изменили — откройте дополнение заново.', $name));
    }

    public static function formulaChanged(string $name, string $current, string $expected): self
    {
        return new self(\sprintf('Формулу «%s» изменили вручную («%s»), а дополнение заменило бы шаблонную («%s»). Чтобы итоги не сломались, верните шаблонную формулу или включите статьи маркетплейса в итоги сами.', $name, $current, $expected));
    }
}
