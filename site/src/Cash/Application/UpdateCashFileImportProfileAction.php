<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Import\CashFileImportProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Обновляет профиль сопоставления колонок файлового импорта.
 */
final class UpdateCashFileImportProfileAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    public function __invoke(CashFileImportProfile $profile, string $name, array $mapping, array $options, string $type): void
    {
        $profile->setName($name);
        $profile->setMapping($mapping);
        $profile->setOptions($options);
        $profile->setType($type);

        $this->entityManager->flush();
    }
}
