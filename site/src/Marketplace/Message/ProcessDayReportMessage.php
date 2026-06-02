<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Автозапуск daily pipeline обработки после загрузки sales_report.
 *
 * Диспатчится из SyncWb/OzonReportHandler после успешного сохранения
 * MarketplaceRawDocument. Handler сбрасывает статус конкретного документа
 * и запускает три шага (sales/returns/costs) через ProcessRawDocumentStepMessage.
 */
final readonly class ProcessDayReportMessage
{
    public function __construct(
        public string $companyId,
        public string $rawDocumentId,
        public bool $forceRefresh = false,
        public ?string $syncStatusId = null,
        public ?string $connectionId = null,
        public ?string $marketplace = null,
        public ?string $reportType = null,
        public ?string $mode = null,
        public ?string $businessDate = null,
    ) {
    }
}
