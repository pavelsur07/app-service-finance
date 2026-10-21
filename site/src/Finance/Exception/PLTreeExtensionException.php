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
        return new self(\sprintf('Статья «%s» (код %s) стоит не на месте шаблона или другого типа: после дополнения она посчиталась бы в итогах дважды или не попала бы в них. Перенесите её на место шаблона или уберите код и повторите.', $name, $code));
    }

    /**
     * @param list<string> $lines
     */
    public static function totalsBroken(array $lines): self
    {
        return new self(\sprintf('После дополнения эти статьи маркетплейса не вошли бы в чистую прибыль ровно один раз: %s. Проверьте, где у вас стоят их группы и какой у них вес, и повторите — ничего не изменено.', implode(', ', $lines)));
    }

    public static function totalTypeChanged(string $name, string $code): self
    {
        return new self(\sprintf('Итог «%s» (код %s) другого типа, чем в шаблоне, — новая формула в нём не считалась бы. Верните тип шаблона и повторите.', $name, $code));
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
