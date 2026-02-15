<?php

declare(strict_types=1);

namespace App\Service;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Company\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Entity\PLDailyTotal;
use App\Entity\ProjectDirection;
use App\Enum\DocumentStatus;
use App\Enum\PlNature;
use App\Repository\DocumentRepository;
use App\Repository\PLDailyTotalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final class PLRegisterUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLDailyTotalRepository $dailyTotals,
        private readonly DocumentRepository $documentRepository,
        private readonly PlNatureResolver $natureResolver,
        private readonly SnapshotCacheInvalidator $snapshotCacheInvalidator,
    ) {
    }

    public function updateForDocument(Document $document): void
    {
        $company = $document->getCompany();
        $day = $document->getDate()->setTime(0, 0);

        $this->clearTotals($company, $day, $day);

        $documents = $this->documentRepository->createQueryBuilder('d')
            ->addSelect('o', 'c', 'pd', 'opd')
            ->leftJoin('d.projectDirection', 'pd')
            ->leftJoin('d.operations', 'o')
            ->leftJoin('o.category', 'c')
            ->leftJoin('o.projectDirection', 'opd')
            ->where('d.company = :company')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->andWhere('d.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', DocumentStatus::ACTIVE)
            ->setParameter('from', $day->setTime(0, 0), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $day->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $aggregated = $this->aggregateDocuments($documents);
        $this->persistAggregatedTotals($company, $aggregated, true);

        $this->em->flush();

        // Пересчёт PL-регистра меняет источник данных для revenue/profit/top_pnl виджетов,
        // поэтому после flush повышаем версию snapshot cache компании.
        // Это важно для согласованности: новый snapshot key должен использоваться только
        // когда пересчитанные агрегаты уже сохранены в БД.
        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }

    public function recalcRange(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $fromDay = $from->setTime(0, 0);
        $toDay = $to->setTime(0, 0);

        $this->clearTotals($company, $fromDay, $toDay);

        $documents = $this->documentRepository->createQueryBuilder('d')
            ->addSelect('o', 'c', 'pd', 'opd')
            ->leftJoin('d.projectDirection', 'pd')
            ->leftJoin('d.operations', 'o')
            ->leftJoin('o.category', 'c')
            ->leftJoin('o.projectDirection', 'opd')
            ->where('d.company = :company')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->andWhere('d.status = :status')
            ->setParameter('company', $company)
            ->setParameter('status', DocumentStatus::ACTIVE)
            ->setParameter('from', $fromDay->setTime(0, 0), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $toDay->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
            ->orderBy('d.date', 'ASC')
            ->getQuery()
            ->getResult();

        $aggregated = $this->aggregateDocuments($documents);
        $this->persistAggregatedTotals($company, $aggregated, true);

        $this->em->flush();

        // recalcRange используется при массовом пересчёте интервала и меняет витрину для dashboard.
        // Повышаем версию snapshot cache только после flush, чтобы новая версия кэша строилась
        // на уже пересчитанных агрегатах, а не на промежуточном состоянии.
        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }

    /**
     * @param iterable<Document> $documents
     *
     * @return array<string, array{
     *   date: \DateTimeImmutable,
     *   projects: array<string, array{
     *     project: ProjectDirection,
     *     categories: array<string, array{category: PLCategory, income: float, expense: float}>,
     *   }>,
     * }>
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
                    'projects' => [],
                ];
            }

            foreach ($document->getOperations() as $operation) {
                if (!$operation instanceof DocumentOperation) {
                    continue;
                }

                $project = method_exists($operation, 'getProjectDirection')
                    ? $operation->getProjectDirection()
                    : null;

                if (!$project instanceof ProjectDirection) {
                    continue;
                }

                $category = method_exists($operation, 'getPlCategory')
                    ? $operation->getPlCategory()
                    : (method_exists($operation, 'getCategory') ? $operation->getCategory() : null);

                if (!$category instanceof PLCategory) {
                    continue;
                }

                $nature = $this->natureResolver->forOperation($operation);

                if (!$nature instanceof PlNature) {
                    continue;
                }

                $projectKey = $project->getId() ?? (string) spl_object_id($project);
                $categoryKey = $category->getId() ?? (string) spl_object_id($category);

                if (!isset($result[$dateKey]['projects'][$projectKey])) {
                    $result[$dateKey]['projects'][$projectKey] = [
                        'project' => $project,
                        'categories' => [],
                    ];
                }

                if (!isset($result[$dateKey]['projects'][$projectKey]['categories'][$categoryKey])) {
                    $result[$dateKey]['projects'][$projectKey]['categories'][$categoryKey] = [
                        'category' => $category,
                        'income' => 0.0,
                        'expense' => 0.0,
                    ];
                }

                $amount = abs((float) $operation->getAmount());

                if (PlNature::INCOME === $nature) {
                    $result[$dateKey]['projects'][$projectKey]['categories'][$categoryKey]['income'] += $amount;
                } else {
                    $result[$dateKey]['projects'][$projectKey]['categories'][$categoryKey]['expense'] += $amount;
                }
            }
        }

        return $result;
    }

    private function persistAggregatedTotals(Company $company, array $aggregated, bool $replace): void
    {
        foreach ($aggregated as $data) {
            $date = $data['date'];

            foreach ($data['projects'] as $projectData) {
                $project = $projectData['project'];

                foreach ($projectData['categories'] as $categoryData) {
                    $income = $categoryData['income'];
                    $expense = $categoryData['expense'];

                    if (0.0 === $income && 0.0 === $expense) {
                        continue;
                    }

                    $this->upsertDailyTotal(
                        $company,
                        $date,
                        $project,
                        $categoryData['category'],
                        $income,
                        $expense,
                        $replace,
                    );
                }
            }
        }
    }

    private function upsertDailyTotal(
        Company $company,
        \DateTimeImmutable $date,
        ProjectDirection $projectDirection,
        PLCategory $category,
        float $income,
        float $expense,
        bool $replace,
    ): void {
        $companyId = $company->getId();
        $categoryId = $category->getId();
        $projectDirectionId = $projectDirection->getId();

        if (null === $companyId || null === $categoryId || null === $projectDirectionId) {
            throw new \LogicException('Unable to upsert PL daily total without identifiers.');
        }

        $this->dailyTotals->upsert(
            $companyId,
            $categoryId,
            $date,
            $projectDirectionId,
            $this->formatAmount($income),
            $this->formatAmount($expense),
            $replace,
            new \DateTimeImmutable(),
        );
    }

    private function clearTotals(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): void
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
