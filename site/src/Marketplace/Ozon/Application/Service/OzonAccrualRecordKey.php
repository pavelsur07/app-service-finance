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
 * - базовый ключ свободен — запись под ним, в `raw_data` метка `_accrual_id`;
 * - базовый ключ занят этим же начислением (та же метка `_accrual_id`) — пропуск: повтор прогона идемпотентен;
 * - занят записью с меткой ДРУГОГО начисления — это отдельное событие, в том числе в другой день: запись под ключом
 *   `-acc{accrual_id}`. Переоформление Ozon приходит как пара «сторно + новое начисление» (01.10.2026: 89 отправлений,
 *   у каждого −X и +X с новым `accrual_id`); возврат от сторно уже записывается, поэтому пропуск нового начисления
 *   оставлял в учёте нулевое нетто там, где у Ozon +X. Сохраняя оба, учёт сходится с нетто Ozon;
 * - занят исторической записью БЕЗ метки в тот же день: если сумма начисления равна сумме записи, запись принадлежит
 *   этому начислению — ей проставляется метка (суммы, привязки и даты не меняются, закрытые периоды не затрагиваются),
 *   новая запись не создаётся; если суммы различаются — это другое начисление, запись под ключом `-acc{accrual_id}`;
 * - занят исторической записью БЕЗ метки другого дня — начисление с собственным `accrual_id` и своей датой, это
 *   отдельное событие: запись под ключом `-acc{accrual_id}`. Метка фиксирует «занятость», поэтому второе начисление
 *   с той же суммой уже получит собственный ключ.
 */
final class OzonAccrualRecordKey
{
    public const ACCRUAL_MARKER = '_accrual_id';

    public const SKIP = 'skip';
    public const INSERT = 'insert';
    /** Запись под базовым ключом — историческая, принадлежит этому начислению: проставить метку, не создавать новую. */
    public const CLAIM_LEGACY = 'claim_legacy';

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
     * @param array<string, array{accrualId: ?string, date: string, amount: string}> $stamps метки уже существующих и добавленных в этом прогоне записей
     * @param string $amount сумма начисления, положительная, два знака
     *
     * @return array{action: string, key: string} действие и ключ записи (для `skip` — базовый)
     */
    public static function decide(string $baseKey, string $accrualId, string $date, string $amount, array $stamps): array
    {
        // Это начисление уже записано под своим суффиксным ключом (например, базовую запись удалили): второй раз не пишем.
        if (isset($stamps[self::suffixed($baseKey, $accrualId)])) {
            return ['action' => self::SKIP, 'key' => $baseKey];
        }

        $known = $stamps[$baseKey] ?? null;
        if (null === $known) {
            return ['action' => self::INSERT, 'key' => $baseKey];
        }

        if ($known['accrualId'] === $accrualId) {
            return ['action' => self::SKIP, 'key' => $baseKey];
        }

        if (null === $known['accrualId'] && $known['date'] === $date && 0 === bccomp($known['amount'], $amount, 2)) {
            return ['action' => self::CLAIM_LEGACY, 'key' => $baseKey];
        }

        return ['action' => self::INSERT, 'key' => self::suffixed($baseKey, $accrualId)];
    }
}
