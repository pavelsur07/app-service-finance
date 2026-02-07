<?php

namespace App\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CashFileImportFacade
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportLogger $importLogger,
    ) {
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    public function createProfile(
        Company $company,
        string $name,
        array $mapping,
        array $options,
        string $type,
    ): CashFileImportProfile {
        $profile = new CashFileImportProfile(
            Uuid::uuid4()->toString(),
            $company,
            $name,
            $mapping,
            $options,
            $type
        );

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    public function updateProfile(
        CashFileImportProfile $profile,
        string $name,
        array $mapping,
        array $options,
        string $type,
    ): void {
        $profile->setName($name);
        $profile->setMapping($mapping);
        $profile->setOptions($options);
        $profile->setType($type);

        $this->entityManager->flush();
    }

    public function deleteProfile(CashFileImportProfile $profile): void
    {
        $this->entityManager->remove($profile);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    public function createJob(
        Company $company,
        MoneyAccount $moneyAccount,
        string $source,
        string $fileName,
        string $fileHash,
        array $mapping,
        array $options,
        ?string $userIdentifier,
    ): CashFileImportJob {
        $importLog = $this->importLogger->start($company, $source, false, $userIdentifier, $fileName);
        $this->entityManager->flush();

        $job = new CashFileImportJob(
            Uuid::uuid4()->toString(),
            $company,
            $moneyAccount,
            $source,
            $fileName,
            $fileHash,
            $mapping,
            $options
        );
        $job->setImportLog($importLog);

        $this->entityManager->persist($job);

        return $job;
    }

    public function commitJob(CashFileImportJob $job): void
    {
        $this->entityManager->flush($job);
    }
}
