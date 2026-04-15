<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceConnection::class);
    }

    /**
     * @return MarketplaceConnection[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.marketplace', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти подключение для компании по маркетплейсу и типу.
     *
     * Тип по умолчанию — SELLER, это сохраняет поведение вызовов без явного типа
     * (основные API-клиенты и UI пока работают только с Seller API).
     * Для Performance API вызывающий обязан передать MarketplaceConnectionType::PERFORMANCE
     * явно — иначе будет возвращено Seller-подключение.
     */
    public function findByMarketplace(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
    ): ?MarketplaceConnection {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.connectionType = :connectionType')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('connectionType', $connectionType)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Найти подключение по компании, маркетплейсу и типу подключения.
     *
     * В отличие от {@see self::findByMarketplace()} тип обязателен — вызывающий
     * должен явно указать SELLER или PERFORMANCE. Используется в контроллерах
     * создания подключений для проверки отсутствия дубликата по конкретному типу.
     */
    public function findByCompanyMarketplaceAndType(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType,
    ): ?MarketplaceConnection {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.connectionType = :connectionType')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('connectionType', $connectionType)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return MarketplaceConnection[]
     */
    public function findActiveConnections(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.isActive = :active')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти подключение по UUID компании, маркетплейсу и типу.
     * Используется в worker-контексте где Company entity не загружена.
     *
     * Тип по умолчанию — SELLER, это сохраняет поведение вызовов без явного типа.
     * Для Performance API вызывающий обязан передать MarketplaceConnectionType::PERFORMANCE
     * явно — иначе будет возвращено Seller-подключение.
     */
    public function findByCompanyIdAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
    ): ?MarketplaceConnection {
        $conn = $this->getEntityManager()->getConnection();
        $id   = $conn->fetchOne(
            'SELECT id FROM marketplace_connections
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND connection_type = :connectionType
             LIMIT 1',
            [
                'companyId'      => $companyId,
                'marketplace'    => $marketplace->value,
                'connectionType' => $connectionType->value,
            ],
        );

        if (!$id) {
            return null;
        }

        return $this->find($id);
    }
}
