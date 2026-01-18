<?php

namespace App\Balance\Service;

use App\Balance\DTO\BalanceRowView;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Balance\Repository\BalanceCategoryRepository;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Entity\Company;

class BalanceBuilder
{
    public function __construct(
        private readonly BalanceCategoryRepository $balanceCategoryRepository,
        private readonly BalanceCategoryLinkRepository $balanceCategoryLinkRepository,
        private readonly MoneyAccountDailyBalanceRepository $moneyAccountDailyBalanceRepository,
        private readonly FundBalanceService $fundBalanceService,
    ) {
    }

    /**
     * @return array{date:\DateTimeImmutable, currencies:list<string>, roots:list<BalanceRowView>, totals: array<string,float>}
     */
    public function buildForCompanyAndDate(Company $company, \DateTimeImmutable $date): array
    {
        $roots = $this->balanceCategoryRepository->findRootByCompany($company);
        $cashTotals = $this->moneyAccountDailyBalanceRepository->getLatestClosingTotalsUpToDate($company, $date);

        $fundTotalsMinor = $this->fundBalanceService->getTotals($company->getId());
        $fundTotals = [];
        foreach ($fundTotalsMinor as $currency => $amountMinor) {
            $fundTotals[$currency] = $this->fundBalanceService->convertMinorToDecimal($amountMinor, $currency);
        }

        $currencies = array_unique(array_merge(array_keys($cashTotals), array_keys($fundTotals)));
        sort($currencies);

        $linksByCategoryId = $this->groupLinksByCategoryId(
            $this->balanceCategoryLinkRepository->findByCompany($company)
        );

        $rootViews = [];
        foreach ($roots as $root) {
            $rootViews[] = $this->buildRow(
                $root,
                $currencies,
                $cashTotals,
                $fundTotals,
                $linksByCategoryId,
            );
        }

        $totals = $this->initializeAmounts($currencies);
        foreach ($rootViews as $view) {
            foreach ($currencies as $currency) {
                $totals[$currency] += $view->amountsByCurrency[$currency] ?? 0.0;
            }
        }

        return [
            'date' => $date,
            'currencies' => $currencies,
            'roots' => $rootViews,
            'totals' => $totals,
        ];
    }

    /**
     * @param list<BalanceCategoryLink> $links
     *
     * @return array<string,list<BalanceCategoryLink>>
     */
    private function groupLinksByCategoryId(array $links): array
    {
        $grouped = [];
        foreach ($links as $link) {
            $categoryId = $link->getCategory()->getId();
            if (null === $categoryId) {
                continue;
            }
            $grouped[$categoryId][] = $link;
        }

        return $grouped;
    }

    /**
     * @param array<string,list<BalanceCategoryLink>> $linksByCategoryId
     * @param array<string,string> $cashTotals
     * @param array<string,float> $fundTotals
     * @param list<string> $currencies
     */
    private function buildRow(
        BalanceCategory $category,
        array $currencies,
        array $cashTotals,
        array $fundTotals,
        array $linksByCategoryId,
    ): BalanceRowView {
        $ownAmounts = $this->calculateOwnAmounts(
            $currencies,
            $linksByCategoryId[$category->getId()] ?? [],
            $cashTotals,
            $fundTotals,
        );

        $childrenViews = [];
        $childrenTotals = $this->initializeAmounts($currencies);
        foreach ($category->getChildren() as $child) {
            $childView = $this->buildRow(
                $child,
                $currencies,
                $cashTotals,
                $fundTotals,
                $linksByCategoryId,
            );
            $childrenViews[] = $childView;

            foreach ($currencies as $currency) {
                $childrenTotals[$currency] += $childView->amountsByCurrency[$currency] ?? 0.0;
            }
        }

        $amountsByCurrency = $this->mergeAmounts($currencies, $ownAmounts, $childrenTotals);

        return new BalanceRowView(
            id: $category->getId() ?? '',
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
     * @return array<string,float>
     */
    private function initializeAmounts(array $currencies): array
    {
        $amounts = [];
        foreach ($currencies as $currency) {
            $amounts[$currency] = 0.0;
        }

        return $amounts;
    }

    /**
     * @param list<string> $currencies
     * @param list<BalanceCategoryLink> $links
     * @param array<string,string> $cashTotals
     * @param array<string,float> $fundTotals
     *
     * @return array<string,float>
     */
    private function calculateOwnAmounts(
        array $currencies,
        array $links,
        array $cashTotals,
        array $fundTotals,
    ): array {
        $amounts = $this->initializeAmounts($currencies);

        foreach ($links as $link) {
            $sign = $link->getSign();
            if (BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL === $link->getSourceType()) {
                foreach ($currencies as $currency) {
                    $amounts[$currency] += $sign * (float) ($cashTotals[$currency] ?? 0.0);
                }
            } elseif (BalanceLinkSourceType::MONEY_FUNDS_TOTAL === $link->getSourceType()) {
                foreach ($currencies as $currency) {
                    $amounts[$currency] += $sign * ($fundTotals[$currency] ?? 0.0);
                }
            }
        }

        return $amounts;
    }

    /**
     * @param list<string> $currencies
     * @param array<string,float> $left
     * @param array<string,float> $right
     *
     * @return array<string,float>
     */
    private function mergeAmounts(array $currencies, array $left, array $right): array
    {
        $amounts = [];
        foreach ($currencies as $currency) {
            $amounts[$currency] = ($left[$currency] ?? 0.0) + ($right[$currency] ?? 0.0);
        }

        return $amounts;
    }
}
