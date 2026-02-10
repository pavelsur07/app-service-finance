<?php

namespace App\Balance\Service;

use App\Balance\DTO\BalanceRowView;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Provider\BalanceValueProviderRegistry;
use App\Balance\ReadModel\BalanceReport;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Balance\Repository\BalanceCategoryRepository;
use App\Company\Entity\Company;

class BalanceBuilder
{
    /** @var array<string, array<string,float>> */
    private array $totalsCache = [];

    public function __construct(
        private readonly BalanceCategoryRepository $balanceCategoryRepository,
        private readonly BalanceCategoryLinkRepository $balanceCategoryLinkRepository,
        private readonly BalanceValueProviderRegistry $registry,
    ) {
    }

    /**
     * @return array{date:\DateTimeImmutable, currencies:list<string>, roots:list<BalanceRowView>, totals: array<string,float>}
     */
    public function buildForCompanyAndDate(Company $company, \DateTimeImmutable $date): array
    {
        $report = $this->buildReportForCompanyAndDate($company, $date);

        return [
            'date' => $report->getDate(),
            'currencies' => $report->getCurrencies(),
            'roots' => $report->getRoots(),
            'totals' => $report->getTotals(),
        ];
    }

    public function buildReportForCompanyAndDate(Company $company, \DateTimeImmutable $date): BalanceReport
    {
        [
            'currencies' => $currencies,
            'roots' => $rootViews,
            'totals' => $totals,
        ] = $this->collectReportData($company, $date);

        return new BalanceReport(
            date: $date,
            currencies: $currencies,
            roots: $rootViews,
            totals: $totals,
        );
    }

    /**
     * @return array{currencies:list<string>, roots:list<BalanceRowView>, totals: array<string,float>}
     */
    private function collectReportData(Company $company, \DateTimeImmutable $date): array
    {
        $this->totalsCache = [];

        $roots = $this->balanceCategoryRepository->findRootByCompany($company);
        $cashTotals = $this->getTotalsCached(BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL, $company, $date);
        $fundTotals = $this->getTotalsCached(BalanceLinkSourceType::MONEY_FUNDS_TOTAL, $company, $date);

        $currencies = array_unique(array_merge(array_keys($cashTotals), array_keys($fundTotals)));
        sort($currencies);

        $linksByCategoryId = $this->groupLinksByCategoryId(
            $this->balanceCategoryLinkRepository->findByCompany($company)
        );

        $rootViews = [];
        foreach ($roots as $root) {
            $rootViews[] = $this->buildRow(
                $root,
                $company,
                $date,
                $currencies,
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
     * @param list<string> $currencies
     */
    private function buildRow(
        BalanceCategory $category,
        Company $company,
        \DateTimeImmutable $date,
        array $currencies,
        array $linksByCategoryId,
    ): BalanceRowView {
        $ownAmounts = $this->calculateOwnAmounts(
            $company,
            $date,
            $currencies,
            $linksByCategoryId[$category->getId()] ?? [],
        );

        $childrenViews = [];
        $childrenTotals = $this->initializeAmounts($currencies);
        foreach ($category->getChildren() as $child) {
            $childView = $this->buildRow(
                $child,
                $company,
                $date,
                $currencies,
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
     *
     * @return array<string,float>
     */
    private function calculateOwnAmounts(
        Company $company,
        \DateTimeImmutable $date,
        array $currencies,
        array $links,
    ): array {
        $amounts = $this->initializeAmounts($currencies);

        foreach ($links as $link) {
            $sign = $link->getSign();
            $totals = $this->getTotalsCached($link->getSourceType(), $company, $date);

            foreach ($currencies as $currency) {
                $amounts[$currency] += $sign * ($totals[$currency] ?? 0.0);
            }
        }

        return $amounts;
    }

    /**
     * @return array<string,float>
     */
    private function getTotalsCached(
        BalanceLinkSourceType $type,
        Company $company,
        \DateTimeImmutable $date,
    ): array {
        $key = $type->value;

        if (!isset($this->totalsCache[$key])) {
            $provider = $this->registry->get($type);
            $this->totalsCache[$key] = $provider->getTotalsForCompanyUpToDate($company, $date);
        }

        return $this->totalsCache[$key];
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
