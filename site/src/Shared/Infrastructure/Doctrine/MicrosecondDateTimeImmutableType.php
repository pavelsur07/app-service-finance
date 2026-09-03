<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Отметка времени, которая ДЕЙСТВИТЕЛЬНО хранит микросекунды.
 *
 * Стандартный `datetime_immutable` форматирует значение как `Y-m-d H:i:s`
 * независимо от объявленной точности колонки, поэтому `TIMESTAMP(6)` в схеме
 * не спасал: микросекунды терялись при записи, и два наблюдения внутри одной
 * секунды становились неразличимыми. Для монотонности статуса это не мелочь:
 * наблюдение `12:00:00.900`, записанное как `12:00:00`, проигрывало более
 * СТАРОМУ `12:00:00.100`, обработанному позже, — статус ехал назад, а в
 * журнале появлялся перевёрнутый переход.
 *
 * Тип отвечает и за зону. Колонка хранит голые «стенные часы», поэтому момент
 * приводится к зоне приложения на записи и разбирается в ней же на чтении:
 * иначе отметка из ClockInterface (UTC) легла бы в базу как UTC и вернулась
 * как местная — сдвиг на смещение зоны при каждом round-trip.
 *
 * Применяется точечно, к отметкам наблюдения, а не ко всем 55 колонкам
 * времени в проекте: смена типа у остальных — отдельное решение с отдельной
 * проверкой обратной совместимости.
 */
final class MicrosecondDateTimeImmutableType extends Type
{
    public const NAME = 'datetime_immutable_us';

    private const FORMAT = 'Y-m-d H:i:s.u';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // Точность объявляется явно: колонка без неё усечёт то, что тип
        // старательно сохранил.
        return 'TIMESTAMP(6) WITHOUT TIME ZONE';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            // Зона приводится к зоне приложения ПЕРЕД форматированием.
            //
            // Колонка без зоны хранит голые «стенные часы», а читатель ниже
            // разбирает их в зоне приложения. Записать момент как есть значило
            // бы положить UTC-время и прочитать его как местное: отметка,
            // пришедшая из ClockInterface (UTC), возвращалась бы сдвинутой на
            // смещение зоны — три часа для Europe/Moscow.
            return $value->setTimezone(self::applicationTimezone())->format(self::FORMAT);
        }

        throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', \DateTimeImmutable::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'string', \DateTimeImmutable::class]);
        }

        // Прочитанное без дробной части — нормальное состояние: строки,
        // записанные до перехода на этот тип, микросекунд не содержат.
        // Зона указывается явно: полагаться на умолчание процесса значило бы
        // читать одну и ту же строку по-разному в разных окружениях.
        $timezone = self::applicationTimezone();
        $converted = \DateTimeImmutable::createFromFormat(self::FORMAT, $value, $timezone)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);

        if (false === $converted) {
            throw ConversionException::conversionFailedFormat($value, $this->getName(), self::FORMAT);
        }

        return $converted;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }

    private static function applicationTimezone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }
}
