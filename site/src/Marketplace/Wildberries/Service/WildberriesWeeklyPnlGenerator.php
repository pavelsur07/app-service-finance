<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service;

use App\Entity\Company;
use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Enum\DocumentType;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;
use App\Repository\PLCategoryRepository;
use App\Service\PLRegisterUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class WildberriesWeeklyPnlGenerator
{
    public function __construct(
        private readonly WildberriesReportDetailRepository $details,
        private readonly WildberriesReportDetailMappingResolver $mappingResolver,
        private readonly WildberriesReportDetailSourceFieldProvider $sourceFieldProvider,
        private readonly PLCategoryRepository $plCategories,
        private readonly PLRegisterUpdater $plRegisterUpdater,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *   totals: array<string, float>,
     *   unmapped: array<int, array{
     *     supplierOperName: ?string,
     *     docTypeName: ?string,
     *     rowsCount: int
     *   }>
     * }
     */
    public function aggregateForPeriod(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->details->createQueryBuilder('wrd')
            ->andWhere('wrd.company = :company')
            ->andWhere('wrd.rrDt IS NOT NULL')
            ->andWhere('wrd.rrDt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        $totals = [];
        $unmapped = [];

        foreach ($rows as $row) {
            $mappings = $this->mappingResolver->resolveManyForRow($company, $row);

            if ([] === $mappings) {
                $key = \sprintf(
                    '%s|%s',
                    (string) $row->getSupplierOperName(),
                    (string) $row->getDocTypeName(),
                );

                if (!isset($unmapped[$key])) {
                    $unmapped[$key] = [
                        'supplierOperName' => $row->getSupplierOperName(),
                        'docTypeName' => $row->getDocTypeName(),
                        'rowsCount' => 0,
                    ];
                }

                ++$unmapped[$key]['rowsCount'];

                continue;
            }

            foreach ($mappings as $mapping) {
                if (!$mapping->isActive()) {
                    continue;
                }

                $sourceField = $mapping->getSourceField();
                $value = $this->sourceFieldProvider->resolveValue($row, $sourceField);

                if (null === $value) {
                    continue;
                }

                $amount = (float) $value * (float) $mapping->getSignMultiplier();

                $plCategoryId = (string) $mapping->getPlCategory()->getId();
                $totals[$plCategoryId] = ($totals[$plCategoryId] ?? 0.0) + $amount;
            }
        }

        return [
            'totals' => $totals,
            'unmapped' => array_values($unmapped),
        ];
    }

    /**
     * @return array{
     *   totals: array<string, float>,
     *   unmapped: array<int, array{
     *     supplierOperName: ?string,
     *     docTypeName: ?string,
     *     rowsCount: int
     *   }>
     * }
     */
    public function aggregateForImportId(
        Company $company,
        string $importId,
    ): array {
        $rows = $this->details->createQueryBuilder('wrd')
            ->andWhere('wrd.company = :company')
            ->andWhere('wrd.importId = :importId')
            ->setParameter('company', $company)
            ->setParameter('importId', $importId)
            ->getQuery()
            ->getResult();

        $totals = [];
        $unmapped = [];

        foreach ($rows as $row) {
            $mappings = $this->mappingResolver->resolveManyForRow($company, $row);

            if ([] === $mappings) {
                $key = \sprintf(
                    '%s|%s',
                    (string) $row->getSupplierOperName(),
                    (string) $row->getDocTypeName(),
                );

                if (!isset($unmapped[$key])) {
                    $unmapped[$key] = [
                        'supplierOperName' => $row->getSupplierOperName(),
                        'docTypeName' => $row->getDocTypeName(),
                        'rowsCount' => 0,
                    ];
                }

                ++$unmapped[$key]['rowsCount'];

                continue;
            }

            foreach ($mappings as $mapping) {
                if (!$mapping->isActive()) {
                    continue;
                }

                $sourceField = $mapping->getSourceField();
                $value = $this->sourceFieldProvider->resolveValue($row, $sourceField);

                if (null === $value) {
                    continue;
                }

                $amount = (float) $value * (float) $mapping->getSignMultiplier();

                $plCategoryId = (string) $mapping->getPlCategory()->getId();
                $totals[$plCategoryId] = ($totals[$plCategoryId] ?? 0.0) + $amount;
            }
        }

        return [
            'totals' => $totals,
            'unmapped' => array_values($unmapped),
        ];
    }

    public function createWeeklyDocumentFromTotals(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $totals,
    ): Document {
        if ([] === $totals) {
            throw new \DomainException('Cannot create WB weekly PnL document: totals are empty');
        }

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $this->applyTotalsToDocument($document, $from, $to, $totals);

        $this->em->persist($document);
        $this->em->flush();

        $this->plRegisterUpdater->updateForDocument($document);

        return $document;
    }

    public function createOrUpdateDocumentForCommissionerReport(
        Company $company,
        WildberriesCommissionerXlsxReport $report,
    ): Document {
        $result = $this->aggregateForImportId($company, $report->getId());

        if ([] !== $result['unmapped']) {
            throw new \DomainException('Найдены немапнутые операции Wildberries. Сначала дополни маппинг.');
        }

        if ([] === $result['totals']) {
            throw new \DomainException('Cannot create WB weekly PnL document: totals are empty');
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

        $this->applyTotalsToDocument($document, $report->getPeriodStart(), $report->getPeriodEnd(), $result['totals']);

        $this->em->persist($document);

        if (null === $documentId) {
            $report->setPnlDocumentId((string) $document->getId());
            $this->em->persist($report);
        }

        $this->em->flush();

        $this->plRegisterUpdater->updateForDocument($document);

        return $document;
    }

    private function applyTotalsToDocument(
        Document $document,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $totals,
    ): void {
        $document
            ->setDate($to)
            ->setType(DocumentType::OTHER)
            ->setDescription(\sprintf('WB: ОПиУ за период %s–%s', $from->format('d.m.Y'), $to->format('d.m.Y')));

        foreach ($totals as $plCategoryId => $amount) {
            $plCategory = $this->plCategories->find($plCategoryId);

            if (null === $plCategory) {
                continue;
            }

            $operation = (new DocumentOperation())
                ->setAmount(number_format($amount, 2, '.', ''))
                ->setCategory($plCategory)
                ->setComment('WB weekly aggregated');

            $document->addOperation($operation);
        }
    }
}
