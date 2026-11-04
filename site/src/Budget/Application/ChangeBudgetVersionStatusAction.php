<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\ChangeBudgetVersionStatusCommand;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetApprovalForbiddenException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\BudgetYearAlreadyApprovedException;
use App\Budget\Exception\InvalidBudgetVersionTransitionException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Утвердить, вернуть в черновик, архивировать, восстановить вариант бюджета.
 *
 * Утверждение и возврат утверждённого в черновик — только владелец компании. Точка входа,
 * коммитит сама; под блокировкой варианта ({@see BudgetVersionLock}) — сохранение ячеек ждёт смены статуса.
 * Проверки до транзакции: доменное исключение внутри неё закрыло бы EntityManager; под
 * блокировкой статус перечитывается, расхождение возвращается значением. Повтор с тем же целевым
 * статусом ничего не меняет. Один утверждённый вариант на год держит частичный unique.
 */
final class ChangeBudgetVersionStatusAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly CompanyFacade $companies,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChangeBudgetVersionStatusCommand $command): void
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);

        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        $from = $version->getStatus();
        $ownerOnly = BudgetVersionStatus::APPROVED === $command->target || BudgetVersionStatus::APPROVED === $from;
        if ($ownerOnly && !$this->companies->isOwner($command->companyId, $command->actorUserId)) {
            throw new BudgetApprovalForbiddenException();
        }
        if ($from === $command->target) {
            return;
        }
        $this->assertAllowed($version, $from, $command);

        try {
            $changedFrom = $this->entityManager->wrapInTransaction(function () use ($version, $command, $from): BudgetVersionStatus {
                BudgetVersionLock::acquire($this->entityManager->getConnection(), $version->getId());
                $this->entityManager->refresh($version);
                $current = $version->getStatus();
                // Права и переход проверены от `$from`; другой статус под блокировкой — конфликт.
                if ($current !== $from) {
                    return $current;
                }
                $now = $this->clock->now();
                match ($command->target) {
                    BudgetVersionStatus::APPROVED => $version->approve($now),
                    BudgetVersionStatus::DRAFT => $version->returnToDraft($now),
                    BudgetVersionStatus::ARCHIVED => $version->archive($now),
                };

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            // Параллельно утвердили другой вариант года; транзакция откачена.
            throw new BudgetYearAlreadyApprovedException($version->getYear(), null);
        }

        if ($changedFrom !== $from) {
            // Статус сменили параллельно: уже целевой — результат тот же, иначе отказ.
            if ($changedFrom === $command->target) {
                return;
            }
            throw new InvalidBudgetVersionTransitionException($changedFrom, 'изменить');
        }

        $this->logger->info('Budget: статус варианта изменён', [
            'companyId' => $command->companyId,
            'versionId' => $command->versionId,
            'from' => $from->value,
            'to' => $command->target->value,
            'actorUserId' => $command->actorUserId,
        ]);
    }

    private function assertAllowed(BudgetVersion $version, BudgetVersionStatus $from, ChangeBudgetVersionStatusCommand $command): void
    {
        if (!$from->allowsTransitionTo($command->target)) {
            throw new InvalidBudgetVersionTransitionException($from, match ($command->target) {
                BudgetVersionStatus::APPROVED => 'утвердить', BudgetVersionStatus::DRAFT => 'вернуть в черновик', BudgetVersionStatus::ARCHIVED => 'архивировать',
            });
        }
        if (BudgetVersionStatus::APPROVED === $command->target) {
            $approved = $this->versions->findApprovedByYear($command->companyId, $version->getYear());
            if (null !== $approved && $approved->getId() !== $version->getId()) {
                throw new BudgetYearAlreadyApprovedException($version->getYear(), $approved->getName());
            }
        }
    }
}
