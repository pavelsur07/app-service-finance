<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Finance\Enum\PLDocumentSource;
use App\Finance\Enum\PLDocumentStream;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Command\CloseMonthStageCommand;
use App\Marketplace\Application\Command\PreflightMonthCloseCommand;
use App\Marketplace\Application\DTO\PreflightResult;
use App\Marketplace\Application\Source\MarketplaceDataSourceInterface;
use App\Marketplace\DTO\PLEntryDTO;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Оркестратор закрытия одного этапа месяца.
 *
 * Поток:
 *   1. Повторный Preflight (защита от race condition)
 *   2. Найти или создать MarketplaceMonthClose
 *   3. Для каждого Source этапа — агрегировать → создать PLDocument → пометить обработанными
 *   4. Закрыть этап в MarketplaceMonthClose
 *
 * Не использует ActiveCompanyService — companyId через Command.
 * Worker-safe.
 */
final class CloseMonthStageAction
{
    /** @param iterable<MarketplaceDataSourceInterface> $dataSources */
    public function __construct(
        private readonly MonthClosePreflightAction       $preflightAction,
        private readonly MarketplaceMonthCloseRepository $monthCloseRepository,
        private readonly FinanceFacade                   $financeFacade,
        private readonly LoggerInterface                 $logger,
        private readonly iterable                        $dataSources,
    ) {
    }

    /**
     * @return array{monthCloseId: string, plDocumentIds: string[], preflightResult: PreflightResult}
     * @throws \DomainException если preflight не пройден
     */
    public function __invoke(CloseMonthStageCommand $command): array
    {
        $stage       = CloseStage::from($command->stage);
        $marketplace = MarketplaceType::from($command->marketplace);

        $periodFrom = sprintf('%d-%02d-01', $command->year, $command->month);
        $periodTo   = (new \DateTimeImmutable($periodFrom))->modify('last day of this month')->format('Y-m-d');

        $this->logger->info('[MonthClose] Stage close started', [
            'company_id'  => $command->companyId,
            'marketplace' => $command->marketplace,
            'year'        => $command->year,
            'month'       => $command->month,
            'stage'       => $command->stage,
        ]);

        // 1. Повторный Preflight
        $preflightResult = ($this->preflightAction)(new PreflightMonthCloseCommand(
            companyId:   $command->companyId,
            marketplace: $command->marketplace,
            year:        $command->year,
            month:       $command->month,
            stage:       $stage,
        ));

        if (!$preflightResult->canClose()) {
            $errors = implode('; ', array_map(
                static fn($c) => $c->message,
                $preflightResult->getErrors(),
            ));
            throw new \DomainException('Закрытие невозможно: ' . $errors);
        }

        // 2. Найти или создать запись закрытия
        $monthClose = $this->monthCloseRepository->findByPeriod(
            $command->companyId,
            $marketplace,
            $command->year,
            $command->month,
        );

        if ($monthClose === null) {
            $monthClose = new \App\Marketplace\Entity\MarketplaceMonthClose(
                Uuid::uuid4()->toString(),
                $command->companyId,
                $marketplace,
                $command->year,
                $command->month,
            );
        }

        // 3. Обработать каждый Source этапа
        $plDocumentIds = [];
        $source        = $this->resolveSource($marketplace);

        foreach ($this->getSourcesForStage($stage, $marketplace) as $dataSource) {
            $entries = $dataSource->getUnprocessedEntries(
                $command->companyId,
                $command->marketplace,
                $periodFrom,
                $periodTo,
            );

            if (empty($entries)) {
                $this->logger->info('[MonthClose] No entries for source', [
                    'source' => $dataSource->getSourceId(),
                ]);
                continue;
            }

            $plEntries = array_map(
                static fn(array $row) => new PLEntryDTO(
                    plCategoryId: $row['pl_category_id'],
                    projectId:    $row['project_direction_id'] ?? null,
                    amount:       $row['total_amount'],
                    periodDate:   $periodTo,
                    description:  $row['description'] ?? $dataSource->getLabel(),
                    isNegative:   (bool) ($row['is_negative'] ?? false),
                    sortOrder:    (int) ($row['sort_order'] ?? 0),
                ),
                $entries,
            );

            $stream = $this->resolveStream($stage);

            $documentId = $this->financeFacade->createPLDocument(
                companyId:  $command->companyId,
                source:     $source,
                stream:     $stream,
                periodFrom: $periodFrom,
                periodTo:   $periodTo,
                entries:    $plEntries,
            );

            $dataSource->markProcessed(
                $command->companyId,
                $command->marketplace,
                $documentId,
                $periodFrom,
                $periodTo,
            );

            $plDocumentIds[] = $documentId;

            $this->logger->info('[MonthClose] Source processed', [
                'source'      => $dataSource->getSourceId(),
                'document_id' => $documentId,
                'entries'     => count($plEntries),
            ]);
        }

        // 4. Закрыть этап
        $monthClose->closeStage(
            $stage,
            $command->actorUserId,
            $plDocumentIds,
            $preflightResult->toArray(),
        );

        $this->monthCloseRepository->save($monthClose);

        $this->logger->info('[MonthClose] Stage closed', [
            'month_close_id' => $monthClose->getId(),
            'stage'          => $command->stage,
            'pl_documents'   => count($plDocumentIds),
        ]);

        return [
            'monthCloseId'    => $monthClose->getId(),
            'plDocumentIds'   => $plDocumentIds,
            'preflightResult' => $preflightResult,
        ];
    }

    /**
     * @return MarketplaceDataSourceInterface[]
     */
    private function getSourcesForStage(CloseStage $stage, MarketplaceType $marketplace): array
    {
        $result = [];
        foreach ($this->dataSources as $source) {
            if ($source->getStage() === $stage && $source->supports($marketplace)) {
                $result[] = $source;
            }
        }

        return $result;
    }

    private function resolveSource(MarketplaceType $marketplace): PLDocumentSource
    {
        return match ($marketplace) {
            MarketplaceType::WILDBERRIES    => PLDocumentSource::MARKETPLACE_WB,
            MarketplaceType::OZON           => PLDocumentSource::MARKETPLACE_OZON,
            MarketplaceType::YANDEX_MARKET  => PLDocumentSource::MARKETPLACE_YANDEX,
            MarketplaceType::SBER_MEGAMARKET => PLDocumentSource::MARKETPLACE_SBER,
        };
    }

    private function resolveStream(CloseStage $stage): PLDocumentStream
    {
        return match ($stage) {
            CloseStage::SALES_RETURNS => PLDocumentStream::REVENUE,
            CloseStage::COSTS         => PLDocumentStream::COSTS,
        };
    }
}
