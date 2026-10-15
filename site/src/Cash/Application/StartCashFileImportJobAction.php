<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Заводит задание файлового импорта перед отправкой в очередь.
 *
 * Порядок записи прежний (был `CashFileImportFacade::createJob` + `commitJob`):
 * сначала журнал импорта, затем задание со ссылкой на него.
 */
final class StartCashFileImportJobAction
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
    public function __invoke(
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
        $this->entityManager->flush();

        return $job;
    }
}
