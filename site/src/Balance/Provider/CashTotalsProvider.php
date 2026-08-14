<?php

declare(strict_types=1);

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

final class CashTotalsProvider implements BalanceValueProviderInterface
{
    public function __construct(
        private readonly MoneyAccountDailyBalanceRepository $moneyAccountDailyBalanceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(BalanceLinkSourceType $type): bool
    {
        return BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL === $type;
    }

    /**
     * @return array<string, string> currency => decimal string
     */
    public function getTotalsForCompanyUpToDate(string $companyId, \DateTimeImmutable $date): array
    {
        $company = $this->entityManager->getReference(Company::class, $companyId);
        $totals = $this->moneyAccountDailyBalanceRepository->getLatestClosingTotalsUpToDate($company, $date);

        $result = [];
        foreach ($totals as $currency => $amount) {
            $result[$currency] = (string) $amount;
        }

        return $result;
    }
}
