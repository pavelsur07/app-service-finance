<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarkProcessedQuery;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Psr\Log\LoggerInterface;

/**
 * Переобработка raw-документов за период.
 *
 * Используется из:
 *   - MarketplaceController::reprocess() — UI кнопка
 *   - ReprocessMarketplaceCommand        — CLI
 *
 * Типы переобработки (параметр $type):
 *   'all'          — все типы документов
 *   'sales_report' — только продажи / возвраты / затраты
 *   'realization'  — только реализация Ozon
 *
 * Daily pipeline-контракт:
 *   - обязательные шаги daily run: sales, returns, costs;
 *   - документ считается полностью проведённым только при успехе всех обязательных шагов;
 *   - failure любого шага означает неполное проведение документа;
 *   - текущий ручной reprocess-flow сохраняется как fallback/admin.
 *
 * НЕ снимает pl_document_id у ozon_realizations автоматически —
 * это задача ReopenMonthStageAction при переоткрытии месяца.
 * Команда только переобрабатывает сырые данные.
 *
 * @return array{docs: int, sales: int, returns: int, costs: int, realization: int}
 */
final class ReprocessMarketplacePeriodAction
{
    public function __construct(
        private readonly MarketplaceRawDocumentRepository    $rawDocumentRepository,
        private readonly ProcessMarketplaceRawDocumentAction $processRawAction,
        private readonly ProcessOzonRealizationAction        $processRealizationAction,
        private readonly LoggerInterface                     $logger,
    ) {
    }

    /**
     * @return array{docs: int, sales: int, returns: int, costs: int, realization: int}
     */
    public function __invoke(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        string $type = 'all',
    ): array {
        $marketplaceEnum = MarketplaceType::from($marketplace);

        // Определяем какой documentType искать
        $documentType = match ($type) {
            'sales_report'  => 'sales_report',
            'realization'   => 'realization',
            default         => null, // все
        };

        $rawDocs = $this->rawDocumentRepository->findByCompanyAndPeriod(
            $companyId,
            $marketplaceEnum,
            $periodFrom,
            $periodTo,
            $documentType,
        );

        $stats = ['docs' => 0, 'sales' => 0, 'returns' => 0, 'costs' => 0, 'realization' => 0];

        foreach ($rawDocs as $doc) {
            $docId   = $doc->getId();
            $docType = $doc->getDocumentType();

            $this->logger->info('[Reprocess] Processing raw doc', [
                'doc_id'    => $docId,
                'doc_type'  => $docType,
                'period'    => $periodFrom->format('Y-m-d') . ' – ' . $periodTo->format('Y-m-d'),
            ]);

            if ($docType === 'realization' && $marketplaceEnum === MarketplaceType::OZON) {
                $result = ($this->processRealizationAction)($companyId, $docId);
                $stats['realization'] += $result['created'] + $result['updated'];
            } elseif ($docType === 'sales_report') {
                $cmd = new ProcessMarketplaceRawDocumentCommand($companyId, $docId, 'sales');
                $stats['sales'] += ($this->processRawAction)($cmd);

                $cmd = new ProcessMarketplaceRawDocumentCommand($companyId, $docId, 'returns');
                $stats['returns'] += ($this->processRawAction)($cmd);

                $cmd = new ProcessMarketplaceRawDocumentCommand($companyId, $docId, 'costs');
                $stats['costs'] += ($this->processRawAction)($cmd);
            }

            $stats['docs']++;
        }

        $this->logger->info('[Reprocess] Completed', array_merge(['companyId' => $companyId], $stats));

        return $stats;
    }
}
