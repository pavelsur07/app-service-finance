<?php

declare(strict_types=1);

namespace App\Telegram\Repository;

use App\Entity\Company;
use App\Telegram\Entity\TelegramBot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TelegramBotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramBot::class);
    }

    /**
     * Возвращает всех ботов компании, отсортированных по дате создания (новые сверху).
     *
     * @return TelegramBot[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->findBy(['company' => $company], ['createdAt' => 'DESC']);
    }

    /**
     * Находит бота по его идентификатору с проверкой принадлежности компании.
     */
    public function findOneByIdAndCompany(string $id, Company $company): ?TelegramBot
    {
        return $this->findOneBy([
            'id' => $id,
            'company' => $company,
        ]);
    }
}
