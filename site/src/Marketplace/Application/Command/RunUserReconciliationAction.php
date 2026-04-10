<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Application\Reconciliation\OzonReportParserFacade;
use App\Marketplace\Entity\ReconciliationSession;
use App\Marketplace\Infrastructure\Query\CostReconciliationQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Use-case: запустить сверку xlsx с данными marketplace_costs.
 *
 * Отличие от ReconcileCostsAction: результат пишется в ReconciliationSession,
 * а не в MarketplaceMonthClose.settings.
 */
final class RunUserReconciliationAction
{
    public function __construct(
        private readonly OzonReportParserFacade $parserFacade,
        private readonly CostReconciliationQuery $reconciliationQuery,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, ReconciliationSession $session): void
    {
        try {
            $reportResult = $this->parserFacade->parseFromStoragePath(
                $session->getStoredFilePath(),
            );

            $reconcileResult = $this->reconciliationQuery->reconcile(
                $companyId,
                $session->getMarketplace(),
                $session->getPeriodFrom()->format('Y-m-d'),
                $session->getPeriodTo()->format('Y-m-d'),
                $reportResult,
            );

            $session->markCompleted($reconcileResult);
            $this->em->flush();
        } catch (\Throwable $e) {
            $session->markFailed(mb_substr($e->getMessage(), 0, 1024));
            $this->em->flush();

            throw $e;
        }
    }
}
