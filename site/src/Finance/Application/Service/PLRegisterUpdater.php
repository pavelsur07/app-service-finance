<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Application\DTO\PLRegisterRow;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Entity\PLCategory;
use App\Finance\Entity\PLDailyTotal;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PlNature;
use App\Finance\Repository\DocumentRepository;
use App\Finance\Repository\PLDailyTotalRepository;
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
        $day = $document->getDate()->setTime(0, 0);

        $this->recalcDays($document->getCompany(), $day, $day);
    }

    public function recalcRange(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $this->recalcDays($company, $from->setTime(0, 0), $to->setTime(0, 0));
    }

    /**
     * Строки регистра, которые пересчёт записал бы за окно, — без записи. Источник сверки
     * `app:finance:verify-pl-register`: тот же отбор документов и та же агрегация, что у пересчёта.
     *
     * @return list<PLRegisterRow>
     */
    public function calculateRows(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $this->flattenRows($this->aggregateDocuments(
            $this->loadDocuments($company, $from->setTime(0, 0), $to->setTime(0, 0)),
        ));
    }

    /**
     * Замена окна регистра: DELETE, чтение документов и upsert — одной транзакцией под блокировкой
     * регистра компании. Во внешней транзакции вызывающего (документ + регистр одним commit)
     * DBAL вкладывает её точкой сохранения, блокировка держится до внешнего commit. Без внешней
     * транзакции окно всё равно заменяется атомарно. flush() здесь нет: записи — DBAL, а UnitOfWork
     * вызывающего сохраняет он сам.
     */
    private function recalcDays(Company $company, \DateTimeImmutable $fromDay, \DateTimeImmutable $toDay): void
    {
        $companyId = $company->getId()
            ?? throw new \LogicException('Unable to recalculate PL register without company id.');

        $this->em->getConnection()->transactional(function () use ($company, $companyId, $fromDay, $toDay): void {
            $this->dailyTotals->acquireRecalculationLock($companyId);
            $this->clearTotals($company, $fromDay, $toDay);
            $this->persistRows($company, $this->flattenRows(
                $this->aggregateDocuments($this->loadDocuments($company, $fromDay, $toDay)),
            ));
        });

        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }

    /**
     * @return list<Document>
     */
    private function loadDocuments(Company $company, \DateTimeImmutable $fromDay, \DateTimeImmutable $toDay): array
    {
        return $this->documentRepository->createQueryBuilder('d')
            ->addSelect('o', 'c', 'pd', 'opd')
            ->leftJoin('d.projectDirection', 'pd')
            ->leftJoin('d.operations', 'o')
            ->leftJoin('o.category', 'c')
            ->leftJoin('o.projectDirection', 'opd')
            ->where('d.company = :company')
            ->andWhere('d.date BETWEEN :from AND :to')
            ->andWhere('d.status = :status')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('status', DocumentStatus::ACTIVE)
            ->setParameter('from', $fromDay->setTime(0, 0), Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $toDay->setTime(23, 59, 59), Types::DATETIME_IMMUTABLE)
            ->orderBy('d.date', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param iterable<Document> $documents
     *
     * @return array<string, array{
     *   date: \DateTimeImmutable,
     *   projects: array<string, array{
     *     project: ProjectDirection,
     *     responsibilityCenters: array<string, array{
     *       responsibilityCenterId: ?string,
     *       categories: array<string, array{
     *         category: PLCategory,
     *         income: float,
     *         expense: float,
     *         mpIncomeSigned: float,
     *         mpExpenseSigned: float,
     *       }>,
     *     }>,
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

                // FIX: если проект не указан на операции — берём проект документа.
                // Ранее операции без projectDirection молча пропускались через continue,
                // из-за чего документы ОПиУ без проекта на операции не попадали в PLDailyTotal
                // и, как следствие, не отображались в отчёте /finance/report/preview.
                $project = method_exists($operation, 'getProjectDirection')
                    ? $operation->getProjectDirection()
                    : null;

                if (!$project instanceof ProjectDirection) {
                    $project = $document->getProjectDirection();
                }

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
                $responsibilityCenterId = $operation->getResponsibilityCenterId() ?? $document->getResponsibilityCenterId();
                $responsibilityCenterKey = $responsibilityCenterId ?? '';
                $categoryKey = $category->getId() ?? (string) spl_object_id($category);

                if (!isset($result[$dateKey]['projects'][$projectKey])) {
                    $result[$dateKey]['projects'][$projectKey] = [
                        'project' => $project,
                        'responsibilityCenters' => [],
                    ];
                }

                if (!isset($result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey])) {
                    $result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey] = [
                        'responsibilityCenterId' => $responsibilityCenterId,
                        'categories' => [],
                    ];
                }

                if (!isset($result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey]['categories'][$categoryKey])) {
                    $result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey]['categories'][$categoryKey] = [
                        'category' => $category,
                        'income' => 0.0,
                        'expense' => 0.0,
                        'mpIncomeSigned' => 0.0,
                        'mpExpenseSigned' => 0.0,
                    ];
                }

                $rawAmount = (float) $operation->getAmount();

                // marketplace_pl смешивает два соглашения о знаке в одном
                // потоке: COGS/PROMO/OPEX (charge=-X, storno=+X) и REV_*_RETURNS
                // (всегда +X). Накапливаем сумму со знаком, abs возьмём после
                // агрегации всех операций категории за день — иначе REV_SPP_RETURNS
                // с +X уйдёт в expense=-X и упрётся в CHECK chk_pl_daily_totals_amounts.
                if (DocumentType::MARKETPLACE_PL === $document->getType()) {
                    if (PlNature::INCOME === $nature) {
                        $result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey]['categories'][$categoryKey]['mpIncomeSigned'] += $rawAmount;
                    } else {
                        $result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey]['categories'][$categoryKey]['mpExpenseSigned'] += $rawAmount;
                    }

                    continue;
                }

                $amount = abs($rawAmount);

                if (PlNature::INCOME === $nature) {
                    $result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey]['categories'][$categoryKey]['income'] += $amount;
                } else {
                    $result[$dateKey]['projects'][$projectKey]['responsibilityCenters'][$responsibilityCenterKey]['categories'][$categoryKey]['expense'] += $amount;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, array{
     *   date: \DateTimeImmutable,
     *   projects: array<string, array{
     *     project: ProjectDirection,
     *     responsibilityCenters: array<string, array{
     *       responsibilityCenterId: ?string,
     *       categories: array<string, array{
     *         category: PLCategory,
     *         income: float,
     *         expense: float,
     *         mpIncomeSigned: float,
     *         mpExpenseSigned: float,
     *       }>,
     *     }>,
     *   }>,
     * }> $aggregated
     *
     * @return list<PLRegisterRow>
     */
    private function flattenRows(array $aggregated): array
    {
        $rows = [];

        foreach ($aggregated as $data) {
            $date = $data['date'];

            foreach ($data['projects'] as $projectData) {
                $projectDirectionId = $projectData['project']->getId();

                foreach ($projectData['responsibilityCenters'] as $responsibilityCenterData) {
                    foreach ($responsibilityCenterData['categories'] as $categoryData) {
                        $income = $categoryData['income'] + abs($categoryData['mpIncomeSigned']);
                        $expense = $categoryData['expense'] + abs($categoryData['mpExpenseSigned']);

                        if (0.0 === $income && 0.0 === $expense) {
                            continue;
                        }

                        $categoryId = $categoryData['category']->getId();

                        if (null === $categoryId || null === $projectDirectionId) {
                            throw new \LogicException('Unable to upsert PL daily total without identifiers.');
                        }

                        $rows[] = new PLRegisterRow(
                            $date->format('Y-m-d'),
                            $categoryId,
                            $projectDirectionId,
                            $responsibilityCenterData['responsibilityCenterId'],
                            $this->formatAmount($income),
                            $this->formatAmount($expense),
                        );
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<PLRegisterRow> $rows
     */
    private function persistRows(Company $company, array $rows): void
    {
        $companyId = $company->getId();

        if (null === $companyId) {
            throw new \LogicException('Unable to upsert PL daily total without identifiers.');
        }

        $timestamp = new \DateTimeImmutable();

        foreach ($rows as $row) {
            $this->dailyTotals->upsert(
                $companyId,
                $row->categoryId,
                new \DateTimeImmutable($row->date),
                $row->projectDirectionId,
                $row->income,
                $row->expense,
                true,
                $timestamp,
                responsibilityCenterId: $row->responsibilityCenterId,
            );
        }
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
