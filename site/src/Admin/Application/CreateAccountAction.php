<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CreateAccountAction
{
    public function __construct(
        private UserPasswordHasherInterface $userPasswordHasher,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(User $account, string $plainPassword, string $companyName): Company
    {
        $account->setPassword($this->userPasswordHasher->hashPassword($account, $plainPassword));
        $account->setRoles(['ROLE_COMPANY_OWNER']);

        $company = new Company(Uuid::uuid7()->toString(), $account);
        $company->setName(trim($companyName));
        $account->addCompany($company);

        $this->entityManager->persist($account);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        $this->bus->dispatch(new SendRegistrationEmailMessage(
            userId: $account->getId(),
            companyId: $company->getId(),
            createdAt: new \DateTimeImmutable(),
        ));

        return $company;
    }
}
