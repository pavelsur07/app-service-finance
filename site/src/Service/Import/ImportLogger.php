<?php

namespace App\Service\Import;

use App\Entity\Company;
use App\Entity\ImportLog;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ImportLogger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function start(Company $company, string $source, bool $preview, ?string $userId, ?string $fileName): ImportLog
    {
        $log = new ImportLog(Uuid::uuid4()->toString());
        $log->setCompany($company);
        $log->setSource($source);
        $log->setStartedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $log->setPreview($preview);
        $log->setUserId($userId);
        $log->setFileName($fileName);
        $this->entityManager->persist($log);

        return $log;
    }

    public function incCreated(ImportLog $log): void
    {
        $log->setCreatedCount($log->getCreatedCount() + 1);
    }

    public function incSkippedDuplicate(ImportLog $log): void
    {
        $log->setSkippedDuplicates($log->getSkippedDuplicates() + 1);
    }

    public function incError(ImportLog $log): void
    {
        $log->setErrorsCount($log->getErrorsCount() + 1);
    }

    public function finish(ImportLog $log): void
    {
        $log->setFinishedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->entityManager->flush();
    }
}
