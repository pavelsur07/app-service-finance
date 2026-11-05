<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Exception\CompanyDeletionNotAllowedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Снимает пометку удаления. Подключения к внешним системам, отключённые при запросе,
 * обратно не включаются — пользователь включает их сам. Повтор — без изменений.
 */
final class RestoreCompanyDeletionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws CompanyDeletionNotAllowedException
     */
    public function __invoke(Company $company, User $actor): void
    {
        $actorId = (string) $actor->getId();
        if ($company->getUser()?->getId() !== $actorId) {
            throw new CompanyDeletionNotAllowedException();
        }

        $companyId = (string) $company->getId();

        /** @var bool $restored */
        $restored = $this->entityManager->wrapInTransaction(function () use ($company, $companyId): bool {
            // Та же блокировка, что у запроса удаления и окончательной очистки.
            $this->entityManager->getConnection()->executeQuery('SELECT 1 FROM companies WHERE id = ? FOR UPDATE', [$companyId]);
            $this->entityManager->refresh($company);

            if (!$company->cancelDeletion()) {
                return false;
            }

            $this->entityManager->flush();

            return true;
        });

        if ($restored) {
            $this->logger->info('Company deletion cancelled', ['companyId' => $companyId, 'userId' => $actorId]);
        }
    }
}
