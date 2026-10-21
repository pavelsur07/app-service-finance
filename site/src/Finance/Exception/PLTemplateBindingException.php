<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * Сопоставление статей компании с шаблоном ОПиУ отклонено целиком: ни один код не присвоен.
 */
final class PLTemplateBindingException extends \DomainException
{
    public static function unknownCode(string $code): self
    {
        return new self(\sprintf('Кода «%s» нет в шаблоне ОПиУ компании.', $code));
    }

    public static function categoryNotFound(string $categoryId): self
    {
        return new self(\sprintf('Статья ОПиУ %s не найдена у компании.', $categoryId));
    }

    public static function categoryHasCode(string $name, string $code): self
    {
        return new self(\sprintf('У статьи «%s» уже код «%s» — сопоставляются только статьи без кода.', $name, $code));
    }

    public static function typeMismatch(string $name, string $code): self
    {
        return new self(\sprintf('Статья «%s» другого типа, чем статья шаблона %s.', $name, $code));
    }

    public static function levelMismatch(string $name, string $code): self
    {
        return new self(\sprintf('Статья «%s» и статья шаблона %s на разных уровнях: корневая сопоставляется только с корневой, вложенная — с вложенной.', $name, $code));
    }

    public static function notSumming(string $name, string $code): self
    {
        return new self(\sprintf('Статья шаблона %s — сумма вложенных статей, а «%s» считается иначе (формулой или своими данными). Сделайте её подытогом без формулы или выберите другую.', $code, $name));
    }

    public static function codeTaken(string $code): self
    {
        return new self(\sprintf('Код «%s» уже есть у другой статьи компании.', $code));
    }

    public static function concurrentChange(): self
    {
        return new self('Коды статей только что изменили в другом окне — откройте сопоставление заново.');
    }

    public static function categoryTwice(string $name): self
    {
        return new self(\sprintf('Статья «%s» выбрана для двух статей шаблона.', $name));
    }
}
