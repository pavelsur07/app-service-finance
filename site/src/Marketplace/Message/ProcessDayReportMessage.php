<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Автозапуск daily pipeline обработки после загрузки sales_report.
 *
 * Диспатчится из SyncWb/OzonReportHandler после успешного сохранения
 * MarketplaceRawDocument. Handler находит документы за дату и запускает
 * три шага (sales/returns/costs) через ProcessRawDocumentStepMessage.
 */
final readonly class ProcessDayReportMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplace,
        public string $date,
    ) {
    }
}
