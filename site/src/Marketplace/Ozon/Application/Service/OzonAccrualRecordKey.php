<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Service;

/**
 * Ключ записи продажи/возврата из начисления Ozon by-day.
 *
 * Базовый ключ — «отправление + индекс товара» (`ozon-accrual-{ref}-product-{i}`): по нему возврат находит свою продажу
 * и по нему уже лежат исторические записи. Он не различает разные начисления одного отправления и товара,
 * поэтому второе начисление раньше молча отбрасывалось как «уже учтённое» (выручка и возвраты занижались).
 *
 * Правило (решение по `docs/tasks/marketplace-ozon-sale-key/`):
 * - ключ свободен — запись идёт под базовым ключом, в `raw_data` кладётся метка `_accrual_id`;
 * - занят записью с меткой ДРУГОГО начисления в ТОТ ЖЕ день — это отдельное событие (две единицы, частичный возврат):
 *   запись идёт под ключом с суффиксом `-acc{accrual_id}`;
 * - занят записью без метки (историческая), тем же начислением или записью другого дня — запись пропускается как раньше:
 *   исторические и закрытые периоды не меняются, а начисление другого дня (возможное переоформление) не удваивается.
 */
final class OzonAccrualRecordKey
{
    public const ACCRUAL_MARKER = '_accrual_id';

    public static function suffixed(string $baseKey, string $accrualId): string
    {
        return sprintf('%s-acc%s', $baseKey, $accrualId);
    }

    /**
     * Ключи, чьи метки нужно знать до разбора пачки: базовые и с суффиксом.
     *
     * @param list<array{externalId: string, accrualId: string}> $records
     *
     * @return list<string>
     */
    public static function probeKeys(array $records): array
    {
        $keys = [];
        foreach ($records as $record) {
            $keys[$record['externalId']] = true;
            $keys[self::suffixed($record['externalId'], $record['accrualId'])] = true;
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, array{accrualId: ?string, date: string}> $stamps метки уже существующих и добавленных в этом прогоне записей
     *
     * @return string|null ключ, под которым создать запись, либо `null` — пропустить
     */
    public static function resolve(string $baseKey, string $accrualId, string $date, array $stamps): ?string
    {
        $known = $stamps[$baseKey] ?? null;
        if (null === $known) {
            return $baseKey;
        }

        if (null === $known['accrualId'] || $known['accrualId'] === $accrualId || $known['date'] !== $date) {
            return null;
        }

        $suffixed = self::suffixed($baseKey, $accrualId);

        return isset($stamps[$suffixed]) ? null : $suffixed;
    }
}
