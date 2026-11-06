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
        ?string $jobId = null,
    ): CashFileImportJob {
        if (isset($options['selected_descriptions'])) {
            if (!is_array($options['selected_descriptions'])) {
                throw new \DomainException('Некорректный выбор строк.');
            }
            foreach ($options['selected_descriptions'] as $rowId) {
                if (!is_string($rowId)) {
                    throw new \DomainException('Некорректная строка выбора.');
                }
            }
            $options['selected_descriptions'] = array_values(array_unique($options['selected_descriptions']));
            sort($options['selected_descriptions'], \SORT_STRING);
        }
        if (null === $jobId) {
            return $this->create($company, $moneyAccount, $source, $fileName, $fileHash, $mapping, $options, $userIdentifier, null);
        }

        return $this->entityManager->wrapInTransaction(function () use ($company, $moneyAccount, $source, $fileName, $fileHash, $mapping, $options, $userIdentifier, $jobId): CashFileImportJob {
            $this->entityManager->getConnection()->executeQuery("SELECT pg_advisory_xact_lock(hashtext('cash_file_confirmation'), hashtext(:key))", ['key' => $company->getId().':'.$jobId]);

            return $this->create($company, $moneyAccount, $source, $fileName, $fileHash, $mapping, $options, $userIdentifier, $jobId);
        });
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $options
     */
    private function create(Company $company, MoneyAccount $moneyAccount, string $source, string $fileName, string $fileHash, array $mapping, array $options, ?string $userIdentifier, ?string $jobId): CashFileImportJob
    {
        if (null !== $jobId) {
            $existing = $this->entityManager->getRepository(CashFileImportJob::class)->findOneBy(['id' => $jobId, 'company' => $company, 'moneyAccount' => $moneyAccount]);
            if ($existing instanceof CashFileImportJob) {
                if ($existing->getMapping() !== $mapping || $existing->getFileHash() !== $fileHash || $existing->getImportLog()?->getUserId() !== $userIdentifier) {
                    throw new \DomainException('Контекст подтверждения изменился.');
                }
                if ($existing->getOptions() !== $options) {
                    // Progress is mutable, the approved selection and plan are not.
                    foreach (['description_plan', 'selected_descriptions'] as $key) {
                        if (($existing->getOptions()[$key] ?? null) !== ($options[$key] ?? null)) {
                            throw new \DomainException('Подтверждение уже использовано с другим выбором.');
                        }
                    }
                }

                return $existing;
            }
        }
        $importLog = $this->importLogger->start($company, $source, false, $userIdentifier, $fileName);
        $this->entityManager->flush();

        $job = new CashFileImportJob(
            $jobId ?? Uuid::uuid7()->toString(),
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
