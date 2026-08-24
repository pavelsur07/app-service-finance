<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Query;

use App\Balance\DTO\BalanceRowView;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Provider\BalanceValueProviderRegistry;
use App\Balance\ReadModel\BalanceReport;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;

final class BalanceReportQuery
{
    private const MONEY_SCALE = 2;

    /** @var array<string, array<string, string>> */
    private array $totalsCache = [];

    public function __construct(
        private readonly BalanceCategoryRepositoryInterface $balanceCategoryRepository,
        private readonly BalanceCategoryLinkRepository $balanceCategoryLinkRepository,
        private readonly BalanceValueProviderRegistry $registry,
    ) {
    }

    public function buildForCompanyAndDate(string $companyId, \DateTimeImmutable $date): BalanceReport
    {
        $this->totalsCache = [];

        $roots = $this->balanceCategoryRepository->findRootByCompany($companyId);
        $cashTotals = $this->getTotalsCached(BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL, $companyId, $date);
        $fundTotals = $this->getTotalsCached(BalanceLinkSourceType::MONEY_FUNDS_TOTAL, $companyId, $date);

        $currencies = array_unique(array_merge(array_keys($cashTotals), array_keys($fundTotals)));
        sort($currencies);

        $linksByCategoryId = $this->groupLinksByCategoryId(
            $this->balanceCategoryLinkRepository->findByCompany($companyId)
        );

        $rootViews = [];
        foreach ($roots as $root) {
            $rootViews[] = $this->buildRow(
                $root,
                $companyId,
                $date,
                $currencies,
                $linksByCategoryId,
            );
        }

        $totals = $this->initializeAmounts($currencies);
        foreach ($rootViews as $view) {
            foreach ($currencies as $currency) {
                $totals[$currency] = bcadd(
                    $totals[$currency],
                    $view->amountsByCurrency[$currency] ?? '0',
                    self::MONEY_SCALE,
                );
            }
        }

        return new BalanceReport(
            date: $date,
            currencies: $currencies,
            roots: $rootViews,
            totals: $totals,
        );
    }

    /**
     * @param list<BalanceCategoryLink> $links
     *
     * @return array<string, list<BalanceCategoryLink>>
     */
    private function groupLinksByCategoryId(array $links): array
    {
        $grouped = [];
        foreach ($links as $link) {
            $categoryId = $link->getCategory()->getId();
            $grouped[$categoryId][] = $link;
        }

        return $grouped;
    }

    /**
     * @param array<string, list<BalanceCategoryLink>> $linksByCategoryId
     * @param list<string> $currencies
     */
    private function buildRow(
        BalanceCategory $category,
        string $companyId,
        \DateTimeImmutable $date,
        array $currencies,
        array $linksByCategoryId,
    ): BalanceRowView {
        $ownAmounts = $this->calculateOwnAmounts(
            $companyId,
            $date,
            $currencies,
            $linksByCategoryId[$category->getId()] ?? [],
        );

        $childrenViews = [];
        $childrenTotals = $this->initializeAmounts($currencies);
        foreach ($category->getChildren() as $child) {
            $childView = $this->buildRow(
                $child,
                $companyId,
                $date,
                $currencies,
                $linksByCategoryId,
            );
            $childrenViews[] = $childView;

            foreach ($currencies as $currency) {
                $childrenTotals[$currency] = bcadd(
                    $childrenTotals[$currency],
                    $childView->amountsByCurrency[$currency] ?? '0',
                    self::MONEY_SCALE,
                );
            }
        }

        $amountsByCurrency = $this->mergeAmounts($currencies, $ownAmounts, $childrenTotals);

        return new BalanceRowView(
            id: $category->getId(),
            name: $category->getName(),
            type: $category->getType()->value,
            level: $category->getLevel(),
            sortOrder: $category->getSortOrder(),
            isVisible: $category->isVisible(),
            amountsByCurrency: $amountsByCurrency,
            children: $childrenViews,
        );
    }

    /**
     * @param list<string> $currencies
     *
     * @return array<string, string>
     */
    private function initializeAmounts(array $currencies): array
    {
        $amounts = [];
        foreach ($currencies as $currency) {
            $amounts[$currency] = '0';
        }

        return $amounts;
    }

    /**
     * @param list<string> $currencies
     * @param list<BalanceCategoryLink> $links
     *
     * @return array<string, string>
     */
    private function calculateOwnAmounts(
        string $companyId,
        \DateTimeImmutable $date,
        array $currencies,
        array $links,
    ): array {
        $amounts = $this->initializeAmounts($currencies);

        foreach ($links as $link) {
            $sign = $link->getSign();
            $totals = $this->getTotalsCached($link->getSourceType(), $companyId, $date);

            foreach ($currencies as $currency) {
                $amount = $totals[$currency] ?? '0';
                $signedAmount = $sign < 0 ? '-'.$amount : $amount;
                $amounts[$currency] = bcadd($amounts[$currency], $signedAmount, self::MONEY_SCALE);
            }
        }

        return $amounts;
    }

    /**
     * @return array<string, string>
     */
    private function getTotalsCached(
        BalanceLinkSourceType $type,
        string $companyId,
        \DateTimeImmutable $date,
    ): array {
        $key = $type->value.':'.$companyId.':'.$date->format('Y-m-d');

        if (!isset($this->totalsCache[$key])) {
            $provider = $this->registry->get($type);
            $this->totalsCache[$key] = $provider->getTotalsForCompanyUpToDate($companyId, $date);
        }

        return $this->totalsCache[$key];
    }

    /**
     * @param list<string> $currencies
     * @param array<string, string> $left
     * @param array<string, string> $right
     *
     * @return array<string, string>
     */
    private function mergeAmounts(array $currencies, array $left, array $right): array
    {
        $amounts = [];
        foreach ($currencies as $currency) {
            $amounts[$currency] = bcadd(
                $left[$currency] ?? '0',
                $right[$currency] ?? '0',
                self::MONEY_SCALE,
            );
        }

        return $amounts;
    }
}
