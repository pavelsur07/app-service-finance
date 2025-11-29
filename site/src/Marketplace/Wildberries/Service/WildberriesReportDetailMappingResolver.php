<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Service;

use App\Entity\Company;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetail;
use App\Marketplace\Wildberries\Entity\WildberriesReportDetailMapping;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailMappingRepository;
use App\Marketplace\Wildberries\Repository\WildberriesReportDetailRepository;

final class WildberriesReportDetailMappingResolver
{
    public function __construct(private readonly WildberriesReportDetailMappingRepository $repository)
    {
    }

    public function resolveForRow(
        Company $company,
        WildberriesReportDetail $row
    ): ?WildberriesReportDetailMapping {
        $supplierOperName = $row->getSupplierOperName();
        $docTypeName = $row->getDocTypeName();
        $siteCountry = $row->getSiteCountry();

        $mapping = $this->repository->findOneByKey($company, $supplierOperName, $docTypeName, $siteCountry);
        if (null !== $mapping) {
            return $mapping;
        }

        $mapping = $this->repository->findOneByKey($company, $supplierOperName, $docTypeName, null);
        if (null !== $mapping) {
            return $mapping;
        }

        return $this->repository->findOneByKey($company, $supplierOperName, null, null);
    }

    /**
     * @return array<int, array{
     *   supplierOperName: ?string,
     *   docTypeName: ?string,
     *   siteCountry: ?string,
     *   rowsCount: int
     * }>
     */
    public function collectDistinctKeysForCompany(
        Company $company,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        WildberriesReportDetailRepository $detailRepository
    ): array {
        $qb = $detailRepository->createQueryBuilder('wrd')
            ->select('wrd.supplierOperName', 'wrd.docTypeName', 'wrd.siteCountry', 'COUNT(wrd.id) AS rowsCount')
            ->andWhere('wrd.company = :company')
            ->groupBy('wrd.supplierOperName')
            ->addGroupBy('wrd.docTypeName')
            ->addGroupBy('wrd.siteCountry')
            ->setParameter('company', $company);

        if (null !== $from) {
            $qb->andWhere('wrd.rrDt >= :from')
                ->setParameter('from', $from);
        }

        if (null !== $to) {
            $qb->andWhere('wrd.rrDt <= :to')
                ->setParameter('to', $to);
        }

        return $qb->getQuery()->getArrayResult();
    }
}
