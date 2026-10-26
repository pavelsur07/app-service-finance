<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Repository\BankConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаление подключения. Курсоры счетов остаются: новое подключение к тому же банку
 * продолжит загрузку с них, а не перечитает неделю.
 */
final readonly class DeleteBankConnectionAction
{
    public function __construct(
        private BankConnectionRepository $connections,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws BankConnectionNotFoundException подключение не принадлежит компании
     */
    public function __invoke(string $companyId, string $connectionId): void
    {
        $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId)
            ?? throw new BankConnectionNotFoundException($connectionId);

        $this->connections->remove($connection);
        $this->entityManager->flush();
    }
}
