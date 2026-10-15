<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Создаёт профиль сопоставления колонок файлового импорта.
 */
final class CreateCashFileImportProfileAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    public function __invoke(Company $company, string $name, array $mapping, array $options, string $type): CashFileImportProfile
    {
        $profile = new CashFileImportProfile(Uuid::uuid4()->toString(), $company, $name, $mapping, $options, $type);

        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $profile;
    }
}
