<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\RenameBudgetVersionCommand;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Переименовать черновик бюджета. Точка входа, коммитит сама.
 *
 * Под блокировкой варианта ({@see BudgetVersionLock}) статус перечитывается: смена статуса
 * параллельно возвращается значением, а не исключением внутри транзакции (оно закрыло бы
 * EntityManager). Повтор с тем же названием ничего не меняет. Возвращает итоговое название.
 */
final class RenameBudgetVersionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RenameBudgetVersionCommand $command): string
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);

        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        try {
            $name = BudgetVersion::normalizeName($command->name);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidBudgetInputException($e->getMessage());
        }
        if (!$version->isEditable()) {
            throw new BudgetVersionNotEditableException($version->getStatus());
        }
        if ($version->getName() === $name) {
            return $name;
        }

        $result = $this->entityManager->wrapInTransaction(function () use ($version, $name): ?BudgetVersionStatus {
            BudgetVersionLock::acquire($this->entityManager->getConnection(), $version->getId());
            $this->entityManager->refresh($version);
            if (!$version->isEditable()) {
                return $version->getStatus();
            }
            $version->rename($name, $this->clock->now());

            return null;
        });
        if (null !== $result) {
            throw new BudgetVersionNotEditableException($result);
        }

        $this->logger->info('Budget: вариант переименован', [
            'companyId' => $command->companyId,
            'versionId' => $command->versionId,
        ]);

        return $name;
    }
}
