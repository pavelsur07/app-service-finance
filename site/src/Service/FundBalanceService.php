<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\MoneyFund;
use App\Entity\MoneyFundMovement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Intl\Currencies;

class FundBalanceService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{fundId:string,name:string,currency:string,balanceMinor:int}>
     */
    public function getFundBalances(string $companyId): array
    {
        $company = $this->em->getReference(Company::class, $companyId);

        $qb = $this->em->createQueryBuilder();
        $rows = $qb->select('f.id AS fundId', 'f.name AS name', 'f.currency AS currency', 'COALESCE(SUM(m.amountMinor), 0) AS balanceMinor')
            ->from(MoneyFund::class, 'f')
            ->leftJoin(MoneyFundMovement::class, 'm', 'WITH', 'm.fund = f')
            ->where('f.company = :company')
            ->setParameter('company', $company)
            ->groupBy('f.id')
            ->addGroupBy('f.name')
            ->addGroupBy('f.currency')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            return [
                'fundId' => $row['fundId'],
                'name' => $row['name'],
                'currency' => $row['currency'],
                'balanceMinor' => (int) $row['balanceMinor'],
            ];
        }, $rows);
    }

    /**
     * @return array<string,int>
     */
    public function getTotals(string $companyId): array
    {
        $balances = $this->getFundBalances($companyId);
        $totals = [];
        foreach ($balances as $row) {
            $currency = $row['currency'];
            $totals[$currency] = ($totals[$currency] ?? 0) + $row['balanceMinor'];
        }

        ksort($totals);

        return $totals;
    }

    public function convertMinorToDecimal(int $amountMinor, string $currency): float
    {
        $fractionDigits = Currencies::getFractionDigits($currency);
        if (0 === $fractionDigits) {
            return (float) $amountMinor;
        }

        $scale = 10 ** $fractionDigits;

        return $amountMinor / $scale;
    }
}
