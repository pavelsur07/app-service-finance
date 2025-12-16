<?php

declare(strict_types=1);

namespace App\Telegram\Repository;

use App\Telegram\Entity\TelegramBot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TelegramBotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramBot::class);
    }

    // Возвращает активного бота (isActive = true) с сортировкой по дате создания (последний созданный первый)
    public function findActiveBot(): ?TelegramBot
    {
        return $this->createQueryBuilder('bot')
            ->andWhere('bot.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('bot.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Возвращает бота по идентификатору
    public function findOneById(string $id): ?TelegramBot
    {
        return $this->find($id);
    }
}
