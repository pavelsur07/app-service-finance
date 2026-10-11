<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\QueryBuilder;

final readonly class RawDocumentsListQuery
{
    public function __construct(
        private MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function buildQueryBuilder(Company $company, ?MarketplaceType $marketplace = null): QueryBuilder
    {
        $queryBuilder = $this->rawDocumentRepository->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.periodTo', \SortDirection::Descending)
            ->addOrderBy('d.periodFrom', \SortDirection::Descending)
            ->addOrderBy('d.marketplace', \SortDirection::Ascending)
            ->addOrderBy('d.documentType', \SortDirection::Ascending)
            ->addOrderBy('d.syncedAt', \SortDirection::Descending);

        if (null !== $marketplace) {
            $queryBuilder
                ->andWhere('d.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        return $queryBuilder;
    }
}
