<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\DTO\PnlPeriodBlockResult;
use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceMonthCloseRepository;

final readonly class MaybeBlockByClosePeriodAction
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private MarketplaceMonthCloseRepository $monthCloseRepository,
    ) {
    }

    public function __invoke(string $companyId, int $year, int $month, string $shopRef): ?PnlPeriodBlockResult
    {
        $company = $this->companyRepository->findById($companyId);
        if (!$company instanceof Company) {
            return PnlPeriodBlockResult::blocked(sprintf('Company "%s" was not found.', $companyId));
        }

        $lastDay = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('last day of this month')->setTime(0, 0);
        $financeLockBefore = $company->getFinanceLockBefore();
        if (null !== $financeLockBefore && $financeLockBefore >= $lastDay) {
            return PnlPeriodBlockResult::blocked(sprintf('Company finance lock blocks period %04d-%02d.', $year, $month));
        }

        if ('' !== $shopRef) {
            $marketplace = $this->deriveMarketplace($shopRef);
            if (null === $marketplace) {
                return null;
            }

            $close = $this->monthCloseRepository->findByPeriod($companyId, $marketplace, $year, $month);

            return $close instanceof MarketplaceMonthClose && $this->hasClosedStage($close)
                ? PnlPeriodBlockResult::blocked(sprintf('%s month close blocks period %04d-%02d.', $marketplace->value, $year, $month))
                : null;
        }

        $closes = $this->monthCloseRepository->createQueryBuilder('close')
            ->andWhere('close.companyId = :companyId')
            ->andWhere('close.year = :year')
            ->andWhere('close.month = :month')
            ->setParameter('companyId', $companyId)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->getQuery()
            ->getResult();

        foreach ($closes as $close) {
            if ($close instanceof MarketplaceMonthClose && $this->hasClosedStage($close)) {
                return PnlPeriodBlockResult::blocked(sprintf('Marketplace month close blocks period %04d-%02d.', $year, $month));
            }
        }

        return null;
    }

    private function deriveMarketplace(string $shopRef): ?MarketplaceType
    {
        $normalized = strtolower($shopRef);

        if (str_starts_with($normalized, 'ozon')) {
            return MarketplaceType::OZON;
        }

        if (str_starts_with($normalized, 'wb') || str_starts_with($normalized, 'wildberries')) {
            return MarketplaceType::WILDBERRIES;
        }

        return null;
    }

    private function hasClosedStage(MarketplaceMonthClose $close): bool
    {
        return $close->getStageSalesReturnsStatus()->isClosed()
            || $close->getStageCostsStatus()->isClosed();
    }
}
