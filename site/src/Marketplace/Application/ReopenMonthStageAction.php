<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarkProcessedQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;

final class ReopenMonthStageAction
{
    public function __construct(
        private readonly MarketplaceMonthCloseRepository      $monthCloseRepository,
        private readonly CompanyFacade                        $companyFacade,
        private readonly MarkProcessedQuery                  $markProcessedQuery,
        private readonly MarketplaceOzonRealizationRepository $ozonRealizationRepository,
    ) {
    }

    public function __invoke(ReopenMonthStageCommand $command): void
    {
        $marketplace = MarketplaceType::from($command->marketplace);

        $monthClose = $this->monthCloseRepository->findByPeriod(
            $command->companyId,
            $marketplace,
            $command->year,
            $command->month,
        );

        if ($monthClose === null || !$monthClose->isStageClosed($command->stage)) {
            throw new \DomainException('Этап не найден или не закрыт.');
        }

        $company = $this->companyFacade->findById($command->companyId);
        if ($company === null) {
            throw new \DomainException('Компания не найдена.');
        }

        $lockBefore = $company->getFinanceLockBefore();
        if ($lockBefore !== null && $monthClose->getPeriodEnd() <= $lockBefore) {
            throw new \DomainException(sprintf(
                'Период заблокирован для редактирования (дата блокировки: %s).',
                $lockBefore->format('d.m.Y'),
            ));
        }

        $documentIds = $monthClose->getStagePLDocumentIds($command->stage);
        $periodFrom  = sprintf('%d-%02d-01', $monthClose->getYear(), $monthClose->getMonth());
        $periodTo    = $monthClose->getPeriodEnd()->format('Y-m-d');

        $this->rollbackProcessedMarks(
            companyId: $command->companyId,
            marketplace: $command->marketplace,
            stage: $command->stage,
            plDocumentIds: $documentIds,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
        );

        $monthClose->reopenStage($command->stage);
        $this->monthCloseRepository->save($monthClose);
    }

    /**
     * @param string[] $plDocumentIds
     */
    private function rollbackProcessedMarks(
        string $companyId,
        string $marketplace,
        CloseStage $stage,
        array $plDocumentIds,
        string $periodFrom,
        string $periodTo,
    ): void {
        match ($stage) {
            CloseStage::SALES_RETURNS => $this->rollbackSalesReturns(
                $companyId,
                $marketplace,
                $plDocumentIds,
                $periodFrom,
                $periodTo,
            ),
            CloseStage::COSTS => $plDocumentIds !== []
                ? $this->markProcessedQuery->unmarkCostsByDocumentIds($companyId, $marketplace, $plDocumentIds)
                : $this->markProcessedQuery->unmarkCostsByPeriod($companyId, $marketplace, $periodFrom, $periodTo),
        };
    }

    /**
     * @param string[] $plDocumentIds
     */
    private function rollbackSalesReturns(
        string $companyId,
        string $marketplace,
        array $plDocumentIds,
        string $periodFrom,
        string $periodTo,
    ): void {
        if ($plDocumentIds !== []) {
            $this->markProcessedQuery->unmarkSalesByDocumentIds($companyId, $marketplace, $plDocumentIds);
            $this->markProcessedQuery->unmarkReturnsByDocumentIds($companyId, $marketplace, $plDocumentIds);
        } else {
            // Фолбэк для старых/повреждённых month_close записей без сохранённых document IDs:
            // снимаем маркировку за период этапа.
            $this->markProcessedQuery->unmarkSalesByPeriod($companyId, $marketplace, $periodFrom, $periodTo);
            $this->markProcessedQuery->unmarkReturnsByPeriod($companyId, $marketplace, $periodFrom, $periodTo);
        }

        if ($marketplace === MarketplaceType::OZON->value) {
            if ($plDocumentIds !== []) {
                $this->ozonRealizationRepository->unmarkProcessedByDocumentIds($companyId, $plDocumentIds);
            } else {
                $this->ozonRealizationRepository->unmarkProcessedByPeriod($companyId, $periodFrom, $periodTo);
            }
        }
    }
}
