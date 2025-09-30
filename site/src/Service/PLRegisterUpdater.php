<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\PLDailyTotal;
use App\Enum\PlNature;
use App\Repository\DocumentRepository;
use App\Repository\PLDailyTotalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PLRegisterUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLDailyTotalRepository $dailyTotals,
        private readonly DocumentRepository $documentRepository,
        private readonly PlNatureResolver $natureResolver,
    ) {
    }

    public function updateForDocument(Document $document): void
    {
        $company = $document->getCompany();
        $day = $document->getDate()->setTime(0, 0);

        $this->clearTotals($company, $day, $day);

        $documents = $this->documentRepository->createQueryBuilder('d')
            ->addSelect('o', 'c')
            ->leftJoin('d.operations', 'o')
            ->leftJoin('o.category', 'c')
            ->where('d.company = :company')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $day->setTime(0, 0), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $day->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $aggregated = $this->aggregateDocuments($documents);
        $this->persistAggregatedTotals($company, $aggregated, true);

        $this->em->flush();
    }

    public function recalcRange(Company $company, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $fromDay = $from->setTime(0, 0);
        $toDay = $to->setTime(0, 0);

        $this->clearTotals($company, $fromDay, $toDay);

        $documents = $this->documentRepository->createQueryBuilder('d')
            ->addSelect('o', 'c')
            ->leftJoin('d.operations', 'o')
            ->leftJoin('o.category', 'c')
            ->where('d.company = :company')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $fromDay->setTime(0, 0), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $toDay->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        $aggregated = $this->aggregateDocuments($documents);
        $this->persistAggregatedTotals($company, $aggregated, true);

        $this->em->flush();
    }

    /**
     * @param iterable<Document> $documents
     * @return array<string, array{date: DateTimeImmutable, categories: array<string, array{category: PLCategory, income: float, expense: float}>}>
     */
    private function aggregateDocuments(iterable $documents): array
    {
        $result = [];

        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }

            $date = $document->getDate()->setTime(0, 0);
            $dateKey = $date->format('Y-m-d');

            if (!isset($result[$dateKey])) {
                $result[$dateKey] = [
                    'date' => $date,
                    'categories' => [],
                ];
            }

            foreach ($document->getOperations() as $operation) {
                if (!$operation instanceof DocumentOperation) {
                    continue;
                }

                $category = $operation->getCategory();

                if (!$category instanceof PLCategory) {
                    continue;
                }

                $nature = $this->natureResolver->forOperation($operation);

                if (!$nature instanceof PlNature) {
                    continue;
                }

                $categoryKey = $category->getId() ?? (string) spl_object_id($category);

                if (!isset($result[$dateKey]['categories'][$categoryKey])) {
                    $result[$dateKey]['categories'][$categoryKey] = [
                        'category' => $category,
                        'income' => 0.0,
                        'expense' => 0.0,
                    ];
                }

                $amount = abs((float) $operation->getAmount());

                if ($nature === PlNature::INCOME) {
                    $result[$dateKey]['categories'][$categoryKey]['income'] += $amount;
                } else {
                    $result[$dateKey]['categories'][$categoryKey]['expense'] += $amount;
                }
            }
        }

        return $result;
    }

    private function persistAggregatedTotals(Company $company, array $aggregated, bool $replace): void
    {
        foreach ($aggregated as $data) {
            $date = $data['date'];

            foreach ($data['categories'] as $categoryData) {
                $income = $categoryData['income'];
                $expense = $categoryData['expense'];

                if ($income === 0.0 && $expense === 0.0) {
                    continue;
                }

                $this->upsertDailyTotal(
                    $company,
                    $date,
                    $categoryData['category'],
                    $income,
                    $expense,
                    $replace,
                );
            }
        }
    }

    private function upsertDailyTotal(
        Company $company,
        DateTimeImmutable $date,
        PLCategory $category,
        float $income,
        float $expense,
        bool $replace,
    ): void {
        $entity = $this->dailyTotals->findOneBy([
            'company' => $company,
            'plCategory' => $category,
            'date' => $date,
        ]);

        if (!$entity instanceof PLDailyTotal) {
            $entity = new PLDailyTotal(Uuid::uuid4()->toString(), $company, $date, $category);
        }

        if ($replace) {
            $incomeValue = $income;
            $expenseValue = $expense;
        } else {
            $incomeValue = (float) $entity->getAmountIncome() + $income;
            $expenseValue = (float) $entity->getAmountExpense() + $expense;
        }

        $entity->setAmountIncome($this->formatAmount($incomeValue));
        $entity->setAmountExpense($this->formatAmount($expenseValue));
        $entity->setUpdatedAt(new DateTimeImmutable());

        $this->em->persist($entity);
    }

    private function clearTotals(Company $company, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->em->createQueryBuilder()
            ->delete(PLDailyTotal::class, 't')
            ->where('t.company = :company')
            ->andWhere('t.date BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
