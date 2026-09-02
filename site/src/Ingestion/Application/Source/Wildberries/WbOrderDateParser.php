<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Wildberries;

/**
 * Разбор отметок времени Wildberries.
 *
 * Одно место на весь модуль: водяной знак курсора в коннекторе и дата заказа в
 * маппере обязаны понимать время одинаково. Две копии этого знания разошлись
 * бы, и расхождение проявилось бы сдвигом заказов на три часа — незаметно.
 */
final class WbOrderDateParser
{
    /**
     * statistics-api отдаёт МОСКОВСКОЕ время без указания зоны.
     *
     * Это проверено на данных, а не взято из документации: в снятой выгрузке
     * один и тот же заказ (`rid = eTEST...0001`) имеет
     * `createdAt = 2026-08-30T19:18:04Z` в marketplace-api и
     * `date = 2026-08-30T22:18:04` в statistics-api — ровно +3 часа.
     */
    public const STATISTICS_TIMEZONE = 'Europe/Moscow';

    /**
     * WB подставляет эту дату вместо null (например, в `cancelDate` у
     * неотменённого заказа). Принять её за настоящую значило бы завести заказы
     * первым годом нашей эры.
     */
    private const ZERO_SENTINEL_PREFIX = '0001-01-01';

    private const STATISTICS_FORMATS = [
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:s.u',
    ];

    private const MARKETPLACE_FORMATS = [
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.u\Z',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uP',
    ];

    /**
     * Отметка `dateFrom` для statistics-api.
     *
     * Прямое и обратное преобразование живут рядом намеренно: запрос и разбор
     * ответа обязаны понимать время одинаково, а две копии зоны разошлись бы
     * сдвигом окна на три часа.
     */
    public static function formatStatisticsDateFrom(\DateTimeImmutable $instant): string
    {
        return $instant
            ->setTimezone(new \DateTimeZone(self::STATISTICS_TIMEZONE))
            ->format('Y-m-d\TH:i:s');
    }

    /**
     * Момент из statistics-api, приведённый к UTC.
     */
    public static function parseStatisticsInstant(mixed $value): ?\DateTimeImmutable
    {
        return self::parse($value, self::STATISTICS_FORMATS, new \DateTimeZone(self::STATISTICS_TIMEZONE));
    }

    /**
     * Момент из marketplace-api, приведённый к UTC.
     */
    public static function parseMarketplaceInstant(mixed $value): ?\DateTimeImmutable
    {
        return self::parse($value, self::MARKETPLACE_FORMATS, new \DateTimeZone('UTC'));
    }

    /**
     * @param list<string> $formats
     */
    private static function parse(mixed $value, array $formats, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ('' === $raw || str_starts_with($raw, self::ZERO_SENTINEL_PREFIX)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        foreach ($formats as $format) {
            // Строго по формату, а не через конструктор: тот принимает
            // относительные строки вроде «tomorrow» и молча чинит
            // несуществующие даты (2026-02-30 → 2026-03-02). Заказ получил бы
            // правдоподобную, но выдуманную дату.
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw, $timezone);
            if (false === $parsed) {
                continue;
            }

            // createFromFormat прощает лишнее и «чинит» невозможные даты —
            // заметить это можно только через getLastErrors().
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            // Для форматов со смещением аргумент зоны игнорируется, поэтому
            // приведение к UTC делается явно: Doctrine пишет
            // datetime_immutable в TIMESTAMP WITHOUT TIME ZONE как есть.
            return $parsed->setTimezone($utc);
        }

        return null;
    }
}
