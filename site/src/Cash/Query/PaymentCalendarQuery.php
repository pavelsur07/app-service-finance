<?php

declare(strict_types=1);

namespace App\Cash\Query;

use App\Cash\DTO\ForecastDTO;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Service\PaymentPlan\ForecastBalanceService;
use App\Company\Entity\Company;
use App\Company\Facade\CounterpartyFacade;

final readonly class PaymentCalendarQuery
{
    public function __construct(
        private PaymentPlanRepository $plans,
        private MoneyAccountRepository $accounts,
        private CashflowCategoryRepository $categories,
        private CounterpartyFacade $counterparties,
        private ForecastBalanceService $forecasts,
    ) {
    }

    /** @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function __invoke(Company $company, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, array $filters): array
    {
        $plansQuery = null;
        $forecast = new ForecastDTO();
        $period = null;
        if (null !== $from && null !== $to) {
            $plansQuery = $this->plans->calendarQuery($company, $from, $to, $filters);
            $period = ['from' => $from, 'to' => $to];
            $account = '' !== $filters['account_id'] ? $this->accounts->findOneByIdAndCompanyId($filters['account_id'], (string) $company->getId()) : null;
            $forecast = $this->forecasts->buildForecast($company, $from, $to, $account);
        }
        $statuses = [];
        foreach (\App\Cash\Enum\PaymentPlan\PaymentPlanStatus::cases() as $status) {
            $statuses[$status->value] = $status->value;
        }

        return [
            'plansQuery' => $plansQuery,
            'hasAnyPlans' => $this->plans->count(['company' => $company]) > 0,
            'filters' => $filters,
            'period' => $period,
            'accounts' => $this->accounts->findBy(['company' => $company], ['name' => 'ASC']),
            'categories' => $this->categories->findBy(['company' => $company], ['name' => 'ASC']),
            'counterparties' => $this->counterparties->getSelectable((string) $company->getId(), includeArchived: true),
            'statuses' => $statuses,
            'forecast' => $forecast,
            'forecastSeries' => $forecast->series,
        ];
    }
}
