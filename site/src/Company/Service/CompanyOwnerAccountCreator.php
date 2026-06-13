<?php

declare(strict_types=1);

namespace App\Company\Service;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Message\SendRegistrationEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CompanyOwnerAccountCreator
{
    public function __construct(
        private UserPasswordHasherInterface $userPasswordHasher,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private CompanyMemberRepository $companyMemberRepository,
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

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName(\trim($companyName));
        $user->addCompany($company);

        $this->entityManager->persist($user);
        $this->entityManager->persist($company);

        if (null === $this->companyMemberRepository->findOneByCompanyAndUser($company, $user)) {
            $companyMember = new CompanyMember(
                id: Uuid::uuid4()->toString(),
                company: $company,
                user: $user,
                role: CompanyMember::ROLE_OWNER,
            );
            $this->entityManager->persist($companyMember);
        }

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
