<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Entity\Company;
use App\Finance\Repository\PLMonthlySnapshotRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final class PLSnapshotBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLMonthlySnapshotRepository $monthlySnapshots,
    ) {
    }

    /**
     * Снимок месяца заменяется целиком одной транзакцией соединения, без flush(): чтение
     * регистра, удаление прежних строк и вставка новых коммитятся вместе, чужой UnitOfWork не
     * сохраняется. Пересборки одного месяца компании сериализованы advisory-блокировкой. Во
     * внешней транзакции вызывающего — точка сохранения.
     */
    public function rebuildMonthly(Company $company, string $periodYm): void
    {
        $monthStart = $this->createMonthStart($periodYm);
        $monthEnd = $monthStart->modify('last day of this month');

        $companyId = $company->getId();

        if (null === $companyId) {
            throw new \LogicException('Unable to rebuild PL monthly snapshots without company identifier.');
        }

        $connection = $this->em->getConnection();
        $connection->transactional(function (Connection $connection) use ($companyId, $periodYm, $monthStart, $monthEnd): void {
            $this->monthlySnapshots->acquireRebuildLock($companyId, $periodYm);

            $totals = $connection
                ->createQueryBuilder()
                ->select('pl_category_id')
                ->addSelect('COALESCE(SUM(amount_income), 0) AS income')
                ->addSelect('COALESCE(SUM(amount_expense), 0) AS expense')
                ->from('pl_daily_totals')
                ->where('company_id = :companyId')
                ->andWhere('date BETWEEN :from AND :to')
                ->groupBy('pl_category_id')
                ->setParameter('companyId', $companyId)
                ->setParameter('from', $monthStart, Types::DATE_IMMUTABLE)
                ->setParameter('to', $monthEnd, Types::DATE_IMMUTABLE)
                ->executeQuery()
                ->fetchAllAssociative();

            // Строки статей, которых в регистре месяца больше нет, уходят вместе с остальными;
            // строка «без статьи» не попадает под ON CONFLICT (NULL различны) и без удаления
            // множилась бы при каждой пересборке.
            $connection->executeStatement(
                'DELETE FROM pl_monthly_snapshots WHERE company_id = :companyId AND period = :period',
                ['companyId' => $companyId, 'period' => $periodYm],
            );

            $now = new \DateTimeImmutable();
            foreach ($totals as $row) {
                $categoryId = $row['pl_category_id'] ?? null;

                $this->monthlySnapshots->upsert(
                    $companyId,
                    null !== $categoryId ? (string) $categoryId : null,
                    $periodYm,
                    $this->formatAmount((float) ($row['income'] ?? 0)),
                    $this->formatAmount((float) ($row['expense'] ?? 0)),
                    $now,
                );
            }
        });
    }

    public function rebuildRange(Company $company, string $fromYm, string $toYm): void
    {
        $from = $this->createMonthStart($fromYm);
        $to = $this->createMonthStart($toYm);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        for ($current = $from; $current <= $to; $current = $current->add(new \DateInterval('P1M'))) {
            $this->rebuildMonthly($company, $current->format('Y-m'));
        }
    }

    private function createMonthStart(string $periodYm): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $periodYm);

        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(sprintf('Invalid period format: %s', $periodYm));
        }

        return $date;
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
