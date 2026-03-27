<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Company\Entity\Company;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Doctrine\ORM\QueryBuilder;

final readonly class RawDocumentsListQuery
{
    public function __construct(
        private MarketplaceRawDocumentRepository $rawDocumentRepository,
    ) {
    }

    public function buildQueryBuilder(Company $company): QueryBuilder
    {
        return $this->rawDocumentRepository->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.syncedAt', 'DESC');
    }
}
