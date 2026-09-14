<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\MoySklad\Entity\MoySkladConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class MoySkladConnectionsQuery
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function forCompany(string $companyId): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('connection')
            ->from(MoySkladConnection::class, 'connection')
            ->where('connection.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('connection.createdAt', 'DESC')
            ->addOrderBy('connection.id', 'DESC');
    }
}
