<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.marketplace.raw_processor')]
interface MarketplaceRawProcessorInterface
{
    /**
     * Daily pipeline оркестрирует уже имеющиеся процессоры без изменения их
     * runtime-поведения. Параметр $format добавлен опциональным: вызывающий,
     * который его не передаёт, получает ровно прежний результат.
     *
     * @param string $kind корзина записей: sales / costs / returns / other
     * @param MarketplaceRawFormat|null $format поколение API, из которого получен
     *                                          документ; null означает «формат не
     *                                          определён» и сохраняет прежнее
     *                                          поведение для исторических документов
     */
    public function supports(string|StagingRecordType $type, MarketplaceType $marketplace, string $kind = '', ?MarketplaceRawFormat $format = null): bool;

    public function process(string $companyId, string $rawDocId): int;

    /**
     * Обработка батча из daily pipeline.
     * rawDocId проставляется на создаваемые entity (raw_document_id)
     * и используется для очистки legacy-записей перед вставкой.
     *
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(
        string $companyId,
        MarketplaceType $marketplace,
        array $rawRows,
        ?string $rawDocId = null,
    ): void;
}
