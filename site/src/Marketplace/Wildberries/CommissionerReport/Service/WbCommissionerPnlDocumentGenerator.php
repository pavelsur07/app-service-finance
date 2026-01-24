<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Service;

use App\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Enum\DocumentType;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCostMappingRepository;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbAggregationResult;
use App\Marketplace\Wildberries\Entity\CommissionerReport\WbDimensionValue;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Service\PLRegisterUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WbCommissionerPnlDocumentGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WbCostMappingRepository $costMappingRepository,
        private readonly PLRegisterUpdater $plRegisterUpdater,
    ) {
    }

    public function createOrUpdateForReport(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
    ): Document {
        $aggregationRepository = $this->em->getRepository(WbAggregationResult::class);

        $unmappedCount = (int) $aggregationRepository->createQueryBuilder('result')
            ->select('COUNT(result.id)')
            ->andWhere('result.company = :company')
            ->andWhere('result.report = :report')
            ->andWhere('result.status = :status')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->setParameter('status', 'unmapped')
            ->getQuery()
            ->getSingleScalarResult();

        if ($unmappedCount > 0) {
            throw new \DomainException('Найдены unmapped суммы. Сначала дополни сопоставления.');
        }

        $mappedResults = $aggregationRepository->createQueryBuilder('result')
            ->andWhere('result.company = :company')
            ->andWhere('result.report = :report')
            ->andWhere('result.status = :status')
            ->setParameter('company', $company)
            ->setParameter('report', $report)
            ->setParameter('status', 'mapped')
            ->getQuery()
            ->getResult();

        if ([] === $mappedResults) {
            throw new \DomainException('Нет данных для формирования ОПиУ.');
        }

        $dimensionValuesById = [];
        foreach ($mappedResults as $result) {
            if (!$result instanceof WbAggregationResult) {
                continue;
            }

            $dimensionValue = $result->getDimensionValue();
            if (!$dimensionValue instanceof WbDimensionValue) {
                throw new \DomainException('Не все mapped строки имеют PL категорию.');
            }

            $dimensionValuesById[$dimensionValue->getId()] = $dimensionValue;
        }

        $mappings = $this->costMappingRepository->findByDimensionValues(
            $company,
            array_values($dimensionValuesById)
        );
        $mappingByDimensionId = [];
        foreach ($mappings as $mapping) {
            $mappingByDimensionId[$mapping->getDimensionValue()->getId()] = $mapping;
        }

        $totals = [];
        $categoriesById = [];
        foreach ($mappedResults as $result) {
            if (!$result instanceof WbAggregationResult) {
                continue;
            }

            $dimensionValue = $result->getDimensionValue();
            if (!$dimensionValue instanceof WbDimensionValue) {
                throw new \DomainException('Не все mapped строки имеют PL категорию.');
            }

            $mapping = $mappingByDimensionId[$dimensionValue->getId()] ?? null;
            if (null === $mapping) {
                throw new \DomainException('Не все mapped строки имеют PL категорию.');
            }

            $plCategory = $mapping->getPlCategory();
            $plCategoryId = (string) $plCategory->getId();

            $totals[$plCategoryId] = ($totals[$plCategoryId] ?? 0.0) + (float) $result->getAmount();
            $categoriesById[$plCategoryId] = $plCategory;
        }

        if ([] === $totals) {
            throw new \DomainException('Нет данных для формирования ОПиУ.');
        }

        $documentId = $report->getPnlDocumentId();

        if (null !== $documentId) {
            $document = $this->em->find(Document::class, $documentId);

            if (!$document instanceof Document) {
                throw new \RuntimeException('Не удалось найти документ ОПиУ для отчёта комиссионера.');
            }

            foreach ($document->getOperations()->toArray() as $operation) {
                $document->removeOperation($operation);
            }
        } else {
            $document = new Document(Uuid::uuid4()->toString(), $company);
        }

        $document
            ->setDate($report->getPeriodEnd())
            ->setType(DocumentType::OTHER)
            ->setDescription(sprintf(
                'WB: ОПиУ за период %s–%s (комиссионерский отчёт)',
                $report->getPeriodStart()->format('d.m.Y'),
                $report->getPeriodEnd()->format('d.m.Y')
            ));

        foreach ($totals as $plCategoryId => $amount) {
            $plCategory = $categoriesById[$plCategoryId] ?? null;
            if (null === $plCategory) {
                continue;
            }

            $operation = (new DocumentOperation())
                ->setAmount(number_format($amount, 2, '.', ''))
                ->setCategory($plCategory)
                ->setComment('WB commissioner aggregated');

            $document->addOperation($operation);
        }

        $this->em->persist($document);

        if (null === $documentId) {
            $report->setPnlDocumentId((string) $document->getId());
            $this->em->persist($report);
        }

        $this->em->flush();

        $this->plRegisterUpdater->updateForDocument($document);

        return $document;
    }
}
