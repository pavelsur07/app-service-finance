<?php

namespace App\Deals\Service;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Repository\CompanyMemberRepository;
use App\Deals\DTO\ChargeTypeFormData;
use App\Deals\Entity\ChargeType;
use App\Deals\Exception\AccessDenied;
use App\Deals\Exception\ValidationFailed;
use App\Deals\Repository\ChargeTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ChargeTypeManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChargeTypeRepository $chargeTypeRepository,
        private readonly CompanyMemberRepository $companyMemberRepository,
    ) {
    }

    public function create(ChargeTypeFormData $data, User $user, Company $company): ChargeType
    {
        $this->assertCompanyAccess($user, $company);

        return $this->transactional(function () use ($data, $company): ChargeType {
            $code = (string) $data->code;
            $this->assertCodeUnique($company, $code);

            $chargeType = new ChargeType(
                id: Uuid::uuid4()->toString(),
                company: $company,
                code: $code,
                name: (string) $data->name,
            );

            $data->applyToEntity($chargeType);
            $chargeType->setUpdatedAt(new \DateTimeImmutable());

            $this->em->persist($chargeType);

            return $chargeType;
        });
    }

    public function update(string $id, ChargeTypeFormData $data, User $user, Company $company): ChargeType
    {
        $this->assertCompanyAccess($user, $company);
        $chargeType = $this->findChargeType($id, $company);

        return $this->transactional(function () use ($chargeType, $data, $company): ChargeType {
            $code = (string) $data->code;
            $this->assertCodeUnique($company, $code, $chargeType->getId());

            $data->applyToEntity($chargeType);
            $chargeType->setUpdatedAt(new \DateTimeImmutable());

            return $chargeType;
        });
    }

    public function toggle(string $id, User $user, Company $company): ChargeType
    {
        $this->assertCompanyAccess($user, $company);
        $chargeType = $this->findChargeType($id, $company);

        return $this->transactional(function () use ($chargeType): ChargeType {
            $chargeType->setIsActive(!$chargeType->isActive());
            $chargeType->setUpdatedAt(new \DateTimeImmutable());

            return $chargeType;
        });
    }

    private function transactional(callable $operation): ChargeType
    {
        $this->em->beginTransaction();

        try {
            $result = $operation();
            $this->em->flush();
            $this->em->commit();

            return $result;
        } catch (\Throwable $exception) {
            $this->rollbackSafely();

            throw $exception;
        }
    }

    private function rollbackSafely(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $this->em->rollback();
        }
    }

    private function assertCompanyAccess(User $user, Company $company): void
    {
        if ($company->getUser() === $user) {
            return;
        }

        $member = $this->companyMemberRepository->findActiveOneByCompanyAndUser($company, $user);
        if (!$member) {
            throw new AccessDenied('User has no access to the company.');
        }
    }

    private function findChargeType(string $id, Company $company): ChargeType
    {
        $chargeType = $this->chargeTypeRepository->findOneByIdForCompany($id, $company);
        if (!$chargeType) {
            throw new ValidationFailed('Charge type not found.');
        }

        return $chargeType;
    }

    private function assertCodeUnique(Company $company, string $code, ?string $excludeId = null): void
    {
        $existing = $this->chargeTypeRepository->findOneByCompanyAndCode($company, $code, $excludeId);
        if ($existing) {
            throw new ValidationFailed('Charge type code must be unique within the company.');
        }
    }
}
