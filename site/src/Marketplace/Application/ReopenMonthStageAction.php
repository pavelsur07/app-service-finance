<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Facade\CompanyFacade;
use App\Finance\Facade\FinanceFacade;
use App\Marketplace\Application\Command\ReopenMonthStageCommand;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\MonthCloseStageStatus;
use App\Marketplace\Infrastructure\Query\MarkProcessedQuery;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;
use Psr\Log\LoggerInterface;

final class ReopenMonthStageAction
{
    public function __construct(
        private readonly MarketplaceMonthCloseRepository      $monthCloseRepository,
        private readonly CompanyFacade                        $companyFacade,
        private readonly MarkProcessedQuery                   $markProcessedQuery,
        private readonly MarketplaceOzonRealizationRepository $ozonRealizationRepository,
        private readonly FinanceFacade                        $financeFacade,
        private readonly LoggerInterface                      $logger,
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

        if ($monthClose === null) {
            throw new \DomainException('Этап не найден.');
        }

        $currentStatus = $monthClose->getStageStatus($command->stage);

        // Идемпотентность: если этап уже переоткрыт (например, предыдущее повторное
        // закрытие упало в Messenger) — молча завершаем без ошибки.
        // Маркировки уже были сняты при первом переоткрытии, PLDocument уже удалён.
        if ($currentStatus === MonthCloseStageStatus::REOPENED) {
            $this->logger->info('[MonthClose] Reopen: stage already reopened, skipping', [
                'company_id'  => $command->companyId,
                'marketplace' => $command->marketplace,
                'year'        => $command->year,
                'month'       => $command->month,
                'stage'       => $command->stage->value,
            ]);

            return;
        }

        // Переоткрыть можно только закрытый этап
        if ($currentStatus !== MonthCloseStageStatus::CLOSED) {
            throw new \DomainException('Этап не закрыт.');
        }

        // Проверка блокировки периода
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

        // 1. Удалить PLDocument(ы) из Finance ПЕРЕД снятием маркировок.
        //    Это устраняет задвоение записей ОПиУ при повторном закрытии.
        //    DeletePLDocumentAction идемпотентен — если документ уже удалён, молча пропускает.
        $this->deletePLDocuments($command->companyId, $documentIds);

        // 2. Снять маркировки «обработано» с записей маркетплейса,
        //    чтобы они попали в выборку при следующем закрытии.
        $this->rollbackProcessedMarks(
            companyId:     $command->companyId,
            marketplace:   $command->marketplace,
            stage:         $command->stage,
            plDocumentIds: $documentIds,
            periodFrom:    $periodFrom,
            periodTo:      $periodTo,
        );

        // 3. Переключить статус этапа и сбросить все поля (IDs, даты, snapshot).
        $monthClose->reopenStage($command->stage);
        $this->monthCloseRepository->save($monthClose);

        $this->logger->info('[MonthClose] Stage reopened', [
            'company_id'      => $command->companyId,
            'marketplace'     => $command->marketplace,
            'year'            => $command->year,
            'month'           => $command->month,
            'stage'           => $command->stage->value,
            'deleted_docs'    => count($documentIds),
        ]);
    }

    /**
     * Удаляет PLDocument(ы) из Finance-модуля.
     * Вызывается ДО снятия маркировок — порядок важен:
     * если удаление упадёт, маркировки останутся нетронутыми и данные не будут потеряны.
     *
     * @param string[] $documentIds
     */
    private function deletePLDocuments(string $companyId, array $documentIds): void
    {
        foreach ($documentIds as $documentId) {
            try {
                $this->financeFacade->deletePLDocument($companyId, $documentId);

                $this->logger->info('[MonthClose] PLDocument deleted', [
                    'company_id'  => $companyId,
                    'document_id' => $documentId,
                ]);
            } catch (\DomainException $e) {
                // Документ не принадлежит компании — нештатная ситуация, пробрасываем
                throw $e;
            }
        }
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
        // Снятие маркировок продаж и возвратов.
        // После шага deletePLDocuments() документы уже удалены, поэтому
        // unmark*ByDocumentIds — единственный надёжный путь:
        // фолбэк по периоду оставляем только для старых записей без сохранённых IDs.
        if ($plDocumentIds !== []) {
            $this->markProcessedQuery->unmarkSalesByDocumentIds($companyId, $marketplace, $plDocumentIds);
            $this->markProcessedQuery->unmarkReturnsByDocumentIds($companyId, $marketplace, $plDocumentIds);
        } else {
            // Фолбэк для старых/повреждённых month_close записей без сохранённых document IDs.
            $this->markProcessedQuery->unmarkSalesByPeriod($companyId, $marketplace, $periodFrom, $periodTo);
            $this->markProcessedQuery->unmarkReturnsByPeriod($companyId, $marketplace, $periodFrom, $periodTo);
        }

        // Для Ozon — отдельно снять маркировку реализации.
        if ($marketplace === MarketplaceType::OZON->value) {
            if ($plDocumentIds !== []) {
                $this->ozonRealizationRepository->unmarkProcessedByDocumentIds($companyId, $plDocumentIds);
            } else {
                $this->ozonRealizationRepository->unmarkProcessedByPeriod($companyId, $periodFrom, $periodTo);
            }
        }
    }
}
