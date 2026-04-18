<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Enum;

/**
 * Статус сырого рекламного документа, загруженного из API маркетплейса.
 *
 * DRAFT       — начальное состояние после upsert'а из FetchOzonAdStatisticsHandler;
 * PROCESSED   — Action отработал без ошибок и без пропусков SKU;
 * FAILED      — исключение в Action ИЛИ частичный успех (остался в DRAFT после
 *               транзакции). Терминальный статус, используется как единственный
 *               источник правды об «этот документ не должен больше обрабатываться»
 *               для финализации AdLoadJob: COUNT(PROCESSED) + COUNT(FAILED) ==
 *               COUNT(total). До введения FAILED это же состояние считалось через
 *               счётчики processed_days/failed_days на AdLoadJob — см.
 *               Version20260418000003, которая эти счётчики убрала.
 */
enum AdRawDocumentStatus: string
{
    case DRAFT = 'draft';
    case PROCESSED = 'processed';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Черновик',
            self::PROCESSED => 'Обработан',
            self::FAILED => 'Ошибка',
        };
    }

    public function isDraft(): bool
    {
        return self::DRAFT === $this;
    }

    /**
     * true, если документ в терминальном состоянии (PROCESSED или FAILED) —
     * повторная обработка не требуется. Handler / Action используют это для
     * short-circuit'а на retry Messenger'а.
     */
    public function isTerminal(): bool
    {
        return self::PROCESSED === $this || self::FAILED === $this;
    }
}
