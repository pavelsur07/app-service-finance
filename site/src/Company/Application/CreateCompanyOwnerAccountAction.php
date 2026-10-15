<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Event\CompanyCreatedEvent;
use App\Company\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CreateCompanyOwnerAccountAction
{
    public function __construct(
        private UserPasswordHasherInterface $userPasswordHasher,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private CompanyOwnerMembershipCreator $companyOwnerMembershipCreator,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(
        User $user,
        string $plainPassword,
        string $companyName,
        bool $sendRegistrationEmail = true,
    ): Company {
        $user->setPassword($this->userPasswordHasher->hashPassword($user, $plainPassword));
        $user->setRoles(['ROLE_COMPANY_OWNER']);

        $this->entityManager->persist($user);
        $company = $this->companyOwnerMembershipCreator->createCompany($user, $companyName);

        // Подписчики (Balance, системные статьи Cash) ищут данные через SQL, поэтому компания
        // сохраняется до события. Транзакция — чтобы сбой подписчика не оставил компанию без
        // их данных: так же, как в CompanyController::new.
        $this->entityManager->wrapInTransaction(function () use ($company): void {
            $this->entityManager->flush();
            $this->eventDispatcher->dispatch(new CompanyCreatedEvent((string) $company->getId()));
        });

        if ($sendRegistrationEmail) {
            $this->bus->dispatch(new SendRegistrationEmailMessage(
                userId: (string) $user->getId(),
                companyId: (string) $company->getId(),
                createdAt: new \DateTimeImmutable(),
            ));
        }

        return $company;
    }
}
