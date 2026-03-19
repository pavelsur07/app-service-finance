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
        $this->rollbackProcessedMarks(
            companyId: $command->companyId,
            marketplace: $command->marketplace,
            stage: $command->stage,
            plDocumentIds: $documentIds,
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
    ): void {
        if ($plDocumentIds === []) {
            return;
        }

        match ($stage) {
            CloseStage::SALES_RETURNS => $this->rollbackSalesReturns(
                $companyId,
                $marketplace,
                $plDocumentIds,
            ),
            CloseStage::COSTS => $this->markProcessedQuery->unmarkCostsByDocumentIds(
                $companyId,
                $marketplace,
                $plDocumentIds,
            ),
        };
    }

    /**
     * @param string[] $plDocumentIds
     */
    private function rollbackSalesReturns(
        string $companyId,
        string $marketplace,
        array $plDocumentIds,
    ): void {
        $this->markProcessedQuery->unmarkSalesByDocumentIds($companyId, $marketplace, $plDocumentIds);
        $this->markProcessedQuery->unmarkReturnsByDocumentIds($companyId, $marketplace, $plDocumentIds);

        if ($marketplace === MarketplaceType::OZON->value) {
            $this->ozonRealizationRepository->unmarkProcessedByDocumentIds($companyId, $plDocumentIds);
        }
    }
}
