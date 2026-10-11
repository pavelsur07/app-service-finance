<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BigIntType;

/**
 * BIGINT-колонка, гидрируемая в PHP `string` — как штатный bigint в DBAL 3.
 *
 * DBAL 4 отдаёт BIGINT `int`, если значение помещается в PHP int. Свойства
 * сущностей с суммами и счётчиками объявлены `string`; присвоение `int`
 * приводится к строке, но UnitOfWork хранит исходное значение `int` и сравнивает
 * его строго: `15000 !== '15000'`, и каждая загруженная сущность попадала бы в
 * changeset — лишний UPDATE на каждом flush. Тип возвращает строку сразу, и
 * исходное значение совпадает со значением свойства.
 *
 * SQL-объявление то же, что у штатного bigint: схема и миграции не меняются.
 * На DBAL 3 тип ведёт себя как штатный bigint — вводится заранее, чтобы
 * переход на DBAL 4 не менял гидрацию.
 */
final class StringBigIntType extends BigIntType
{
    public const NAME = 'bigint_string';

    // DBAL 3: имя обязано совпадать с ключом регистрации; в DBAL 4 метода нет.
    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return (string) $value;
    }
}
