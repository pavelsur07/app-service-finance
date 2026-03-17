<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Source;

use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;

/**
 * Контракт источника данных для закрытия месяца.
 *
 * Каждый источник знает:
 *   - для каких маркетплейсов он применим
 *   - к какому этапу закрытия относится
 *   - является ли его отсутствие блокирующим
 *   - как получить необработанные данные для ОПиУ
 *   - как пометить данные обработанными
 *
 * Добавление нового источника = новый класс + тег в DI.
 * Ядро закрытия месяца не меняется.
 */
interface MarketplaceDataSourceInterface
{
    /**
     * Поддерживает ли источник данный маркетплейс.
     */
    public function supports(MarketplaceType $marketplace): bool;

    /**
     * К какому этапу закрытия относится источник.
     */
    public function getStage(): CloseStage;

    /**
     * Идентификатор источника — уникальный строковый ключ.
     * Используется в preflight snapshot и логировании.
     */
    public function getSourceId(): string;

    /**
     * Человекочитаемое название источника.
     */
    public function getLabel(): string;

    /**
     * Агрегировать данные для генерации записей ОПиУ.
     *
     * @return array<int, array<string, mixed>>  строки для PLEntryDTO
     */
    public function getUnprocessedEntries(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array;

    /**
     * Пометить записи обработанными после успешного создания документа ОПиУ.
     */
    public function markProcessed(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int;
}
