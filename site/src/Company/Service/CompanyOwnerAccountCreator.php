<?php

declare(strict_types=1);

namespace App\Company\Service;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CompanyOwnerAccountCreator
{
    public function __construct(
        private UserPasswordHasherInterface $userPasswordHasher,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private CompanyOwnerMembershipCreator $companyOwnerMembershipCreator,
    ) {
    }

    public function create(
        User $user,
        string $plainPassword,
        string $companyName,
        bool $sendRegistrationEmail = true,
    ): Company {
        $user->setPassword($this->userPasswordHasher->hashPassword($user, $plainPassword));
        $user->setRoles(['ROLE_COMPANY_OWNER']);

        $this->entityManager->persist($user);
        $company = $this->companyOwnerMembershipCreator->createCompany($user, $companyName);

        $this->entityManager->flush();

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
