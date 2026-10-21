<?php

declare(strict_types=1);

namespace App\Finance\Exception;

use App\Company\Enum\BusinessProfile;

/**
 * Дерево ОПиУ компании создано шаблоном другого профиля: совпавшие по коду итоги считаются по его
 * формулам, и статьи нового шаблона выпали бы из них. Наследует \DomainException — экран импорта
 * и команда показывают его как любой отказ импорта.
 */
final class PLTemplateProfileMismatchException extends \DomainException
{
    public static function between(BusinessProfile $origin, BusinessProfile $requested): self
    {
        return new self(\sprintf(
            'Дерево ОПиУ создано шаблоном «%s»; шаблон «%s» к нему не применяется — их итоги считаются по-разному, и новые статьи выпали бы из отчёта. Верните профиль «%s» в настройках компании или добавьте статьи вручную.',
            $origin->label(),
            $requested->label(),
            $origin->label(),
        ));
    }
}
