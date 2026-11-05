<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Event\CompanyDeletionRequestedEvent;
use App\Company\Exception\CompanyDeletionConfirmationMismatchException;
use App\Company\Exception\CompanyDeletionNotAllowedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Запрос удаления компании: пометка + отключение подключений модулями, одной транзакцией.
 * Данные остаются Company::DELETION_GRACE_DAYS дней, до этого компанию можно восстановить.
 * Повторный запрос возвращает то же состояние: дата не сдвигается, событие не повторяется.
 */
final class RequestCompanyDeletionAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompanyDeletionNotAllowedException
     * @throws CompanyDeletionConfirmationMismatchException
     */
    public function __invoke(Company $company, User $actor, string $confirmationName): void
    {
        $actorId = (string) $actor->getId();
        if ($company->getUser()?->getId() !== $actorId) {
            throw new CompanyDeletionNotAllowedException();
        }

        if ($company->isDeletionRequested()) {
            return;
        }

        if (!self::namesMatch((string) $company->getName(), $confirmationName)) {
            throw new CompanyDeletionConfirmationMismatchException();
        }

        $companyId = (string) $company->getId();

        /** @var bool $requested */
        $requested = $this->entityManager->wrapInTransaction(function () use ($company, $companyId, $actorId): bool {
            // Блокировка строки компании: параллельный запрос или восстановление ждут нас.
            $this->entityManager->getConnection()->executeQuery('SELECT 1 FROM companies WHERE id = ? FOR UPDATE', [$companyId]);
            $this->entityManager->refresh($company);

            if (!$company->requestDeletion($this->clock->now(), $actorId)) {
                return false;
            }

            $this->eventDispatcher->dispatch(new CompanyDeletionRequestedEvent($companyId));
            $this->entityManager->flush();

            return true;
        });

        if ($requested) {
            $this->logger->info('Company deletion requested', [
                'companyId' => $companyId,
                'userId' => $actorId,
                'dueAt' => $company->getDeletionDueAt()?->format(\DATE_ATOM),
            ]);
        }
    }

    private static function namesMatch(string $companyName, string $confirmationName): bool
    {
        $normalize = static fn (string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return '' !== $normalize($confirmationName) && $normalize($companyName) === $normalize($confirmationName);
    }
}
