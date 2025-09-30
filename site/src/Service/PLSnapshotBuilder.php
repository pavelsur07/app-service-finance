<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Entity\PLMonthlySnapshot;
use App\Repository\PLMonthlySnapshotRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PLSnapshotBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLMonthlySnapshotRepository $monthlySnapshots,
    ) {
    }

    public function rebuildMonthly(Company $company, string $periodYm): void
    {
        $monthStart = $this->createMonthStart($periodYm);
        $monthEnd = $monthStart->modify('last day of this month');

        $totals = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('pl_category_id')
            ->addSelect('COALESCE(SUM(amount_income), 0) AS income')
            ->addSelect('COALESCE(SUM(amount_expense), 0) AS expense')
            ->from('pl_daily_totals')
            ->where('company_id = :companyId')
            ->andWhere('date BETWEEN :from AND :to')
            ->groupBy('pl_category_id')
            ->setParameter('companyId', $company->getId())
            ->setParameter('from', $monthStart, Types::DATE_IMMUTABLE)
            ->setParameter('to', $monthEnd, Types::DATE_IMMUTABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $existingSnapshots = $this->monthlySnapshots->createQueryBuilder('s')
            ->leftJoin('s.plCategory', 'c')
            ->where('s.company = :company')
            ->andWhere('s.period = :period')
            ->setParameter('company', $company)
            ->setParameter('period', $periodYm)
            ->getQuery()
            ->getResult();

        $existingByCategory = [];

        foreach ($existingSnapshots as $snapshot) {
            if (!$snapshot instanceof PLMonthlySnapshot) {
                continue;
            }

            $categoryId = $snapshot->getPlCategory()?->getId();
            $existingByCategory[$this->categoryKey($categoryId)] = $snapshot;
        }

        $processedKeys = [];
        $now = new DateTimeImmutable();

        foreach ($totals as $row) {
            $categoryId = $row['pl_category_id'] ?? null;
            $key = $this->categoryKey($categoryId);

            $income = $this->formatAmount((float) ($row['income'] ?? 0));
            $expense = $this->formatAmount((float) ($row['expense'] ?? 0));

            $snapshot = $existingByCategory[$key] ?? null;

            if (!$snapshot instanceof PLMonthlySnapshot) {
                $category = null;

                if (!empty($categoryId)) {
                    $category = $this->em->getReference(PLCategory::class, $categoryId);
                }

                $snapshot = new PLMonthlySnapshot(
                    Uuid::uuid4()->toString(),
                    $company,
                    $periodYm,
                    $category,
                );
            }

            $snapshot->setAmountIncome($income);
            $snapshot->setAmountExpense($expense);
            $snapshot->setUpdatedAt($now);

            $this->em->persist($snapshot);

            $processedKeys[$key] = true;
        }

        foreach ($existingByCategory as $key => $snapshot) {
            if (!isset($processedKeys[$key])) {
                $this->em->remove($snapshot);
            }
        }

        $this->em->flush();
    }

    public function rebuildRange(Company $company, string $fromYm, string $toYm): void
    {
        $from = $this->createMonthStart($fromYm);
        $to = $this->createMonthStart($toYm);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        for ($current = $from; $current <= $to; $current = $current->add(new DateInterval('P1M'))) {
            $this->rebuildMonthly($company, $current->format('Y-m'));
        }
    }

    private function createMonthStart(string $periodYm): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $periodYm);

        if (!$date instanceof DateTimeImmutable) {
            throw new \InvalidArgumentException(sprintf('Invalid period format: %s', $periodYm));
        }

        return $date;
    }

    private function categoryKey(?string $categoryId): string
    {
        return $categoryId ?? '__null__';
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
