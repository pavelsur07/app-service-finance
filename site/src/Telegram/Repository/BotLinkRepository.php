<?php

declare(strict_types=1);

namespace App\Telegram\Repository;

use App\Telegram\Entity\BotLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

class BotLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BotLink::class);
    }

    /**
     * Находит BotLink по токену с блокировкой записи (PESSIMISTIC_WRITE),
     * чтобы корректно пометить одноразовое использование и защититься от гонок.
     */
    public function findOneByTokenForUpdate(string $token): ?BotLink
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.token = :t')
            ->setParameter('t', $token)
            ->setMaxResults(1);

        $query = $qb->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return $query->getOneOrNullResult();
    }
}
