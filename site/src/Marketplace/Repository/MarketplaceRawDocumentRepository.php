<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceRawDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceRawDocument::class);
    }

    /**
     * @return MarketplaceRawDocument[]
     */
    public function findByCompany(Company $company, int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.syncedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти raw-документы за период для переобработки.
     *
     * Используется в ReprocessMarketplaceCommand.
     * Фильтрует по periodFrom/periodTo документа (перекрытие с запрошенным периодом).
     *
     * @param string|null $documentType  null = все типы | 'sales_report' | 'realization'
     * @return MarketplaceRawDocument[]
     */
    public function findByCompanyAndPeriod(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        ?string $documentType = null,
    ): array {
        $qb = $this->createQueryBuilder('d')
            ->join('d.company', 'c')
            ->where('c.id = :companyId')
            ->andWhere('d.marketplace = :marketplace')
            // Документ перекрывается с периодом если его конец >= начала запроса
            // и его начало <= конца запроса
            ->andWhere('d.periodFrom <= :periodTo')
            ->andWhere('d.periodTo >= :periodFrom')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('periodFrom', $periodFrom)
            ->setParameter('periodTo', $periodTo)
            ->orderBy('d.syncedAt', 'ASC');

        if ($documentType !== null) {
            $qb->andWhere('d.documentType = :documentType')
                ->setParameter('documentType', $documentType);
        }

        return $qb->getQuery()->getResult();
    }
}
