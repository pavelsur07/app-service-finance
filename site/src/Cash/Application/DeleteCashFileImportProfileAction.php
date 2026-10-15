<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Import\CashFileImportProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Удаляет профиль сопоставления колонок файлового импорта.
 */
final class DeleteCashFileImportProfileAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(CashFileImportProfile $profile): void
    {
        $this->entityManager->remove($profile);
        $this->entityManager->flush();
    }
}
