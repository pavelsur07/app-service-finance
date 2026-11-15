<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Domain;

use App\MarketplaceOperations\Enum\OperationKind;

/**
 * Справочник кодов WB по умолчанию (таблица этапа 8.0, `stages/stage-1-category-levels.md`, раздел WB).
 * Вид `null` — решает администратор на экране кодов; решение в `mwb_source_type` важнее справочника.
 */
final class WbSourceTypeSeed
{
    /** @var array<string, array{string, OperationKind|null}> */
    public const TYPES = [
        'wb:field:rebillLogisticCost' => ['Возмещение издержек по перевозке и складским операциям', null],
        'wb:field:paidAcceptance' => ['Платная приёмка и обработка товара', null],
        'wb:field:ppvzReward' => ['Возмещение за выдачу и возврат товаров на ПВЗ', null],
        'wb:field:cashbackCommissionChange' => ['Стоимость участия в программе лояльности', null],
        'wb:deduction:Оказание услуг «WB Продвижение»' => ['Оказание услуг «WB Продвижение»', OperationKind::ADS_SETTLEMENT],
        'wb:deduction:Предоставление услуг по подписке «Джем»' => ['Подписка «Джем»', OperationKind::SUBSCRIPTION],
        'wb:deduction:Отчет об утилизированном товаре (по складу)' => ['Утилизация товара на складе', OperationKind::DISPOSAL],
    ];

    public static function kind(string $sourceType): ?OperationKind
    {
        return self::TYPES[$sourceType][1] ?? null;
    }

    public static function name(string $sourceType): string
    {
        return self::TYPES[$sourceType][0] ?? (string) preg_replace('/^wb:(field|deduction|payout):/u', '', $sourceType);
    }
}
