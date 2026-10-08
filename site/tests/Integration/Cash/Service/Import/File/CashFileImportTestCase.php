<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import\File;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\MessageHandler\Import\CashFileImportHandler;
use App\Cash\Service\Import\File\CashFileImportFacade;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Окружение файлового импорта ДДС: компания со счётом, системным проектом и ЦФО,
 * файл в объектном хранилище, job из CashFileImportFacade — тот же путь, что
 * проходит загрузка из UI.
 */
abstract class CashFileImportTestCase extends IntegrationTestCase
{
    protected const MAPPING = [
        'date' => 'Дата',
        'amount' => 'Сумма',
        'description' => 'Назначение',
        'counterparty' => 'Контрагент',
        'doc_number' => 'Номер',
    ];

    protected Company $company;
    protected MoneyAccount $account;

    /** @var list<string> */
    private array $storedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withEmail('cash-import-'.$companyId.'@example.test')->build();
        $this->company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->withName('Cash File Import Co')
            ->build();
        $this->account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->withCurrency('RUB')
            ->build();
        $systemProject = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $this->company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $systemCenter = new FinancialResponsibilityCenter(
            $companyId,
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->persist($this->account);
        $this->em->persist($systemProject);
        $this->em->persist($systemCenter);
        $this->em->persist(new FinancialResponsibilityCenterProject(
            $companyId,
            $systemProject,
            $systemCenter,
        ));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $storage = $this->storage();
        foreach ($this->storedKeys as $key) {
            $storage->delete($key);
        }

        parent::tearDown();
    }

    /**
     * @param list<array{date: string, amount: string, description: string, counterparty?: string, doc_number?: string}> $rows
     */
    protected function queueCsvImport(array $rows): CashFileImportJob
    {
        $lines = [implode(';', array_values(self::MAPPING))];
        foreach ($rows as $row) {
            $lines[] = implode(';', [
                $row['date'],
                $row['amount'],
                $row['description'],
                $row['counterparty'] ?? '',
                $row['doc_number'] ?? '',
            ]);
        }
        $csv = implode("\n", $lines)."\n";

        $fileHash = hash('sha256', $csv.Uuid::uuid4()->toString());
        $storageKey = sprintf('cash-file-imports/%s.csv', $fileHash);
        $this->storage()->write($storageKey, $csv);
        $this->storedKeys[] = $storageKey;

        /** @var CashFileImportFacade $facade */
        $facade = self::getContainer()->get(CashFileImportFacade::class);
        $job = $facade->createJob(
            $this->company,
            $this->account,
            'cash:file',
            'statement.csv',
            $fileHash,
            self::MAPPING,
            ['stored_ext' => 'csv'],
            null,
        );
        $facade->commitJob($job);

        return $job;
    }

    /**
     * Строки с разными суммами: dedupeHash (дата + сумма + назначение) не совпадает.
     *
     * @param array<int, array{amount?: string, counterparty?: string, doc_number?: string}> $overrides по 0-based номеру строки
     *
     * @return list<array{date: string, amount: string, description: string, counterparty?: string, doc_number?: string}>
     */
    protected function distinctRows(int $count, array $overrides = []): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = array_merge([
                'date' => '01.09.2026',
                'amount' => sprintf('%d,00', 1001 + $i),
                'description' => sprintf('Оплата по счёту %d', $i + 1),
            ], $overrides[$i] ?? []);
        }

        return $rows;
    }

    protected function handle(string $jobId): void
    {
        /** @var CashFileImportHandler $handler */
        $handler = self::getContainer()->get(CashFileImportHandler::class);
        $handler(new CashFileImportMessage($jobId));
    }

    protected function reloadJob(string $jobId): CashFileImportJob
    {
        $this->em->clear();
        $job = $this->em->find(CashFileImportJob::class, $jobId);
        self::assertInstanceOf(CashFileImportJob::class, $job);

        return $job;
    }

    protected function reloadLog(CashFileImportJob $job): ImportLog
    {
        $log = $job->getImportLog();
        self::assertInstanceOf(ImportLog::class, $log);

        return $log;
    }

    protected function countActiveTransactions(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM cash_transaction WHERE company_id = :companyId AND deleted_at IS NULL',
            ['companyId' => $this->company->getId()],
        );
    }

    private function storage(): ObjectStorageInterface
    {
        /** @var ObjectStorageInterface $storage */
        $storage = self::getContainer()->get(ObjectStorageInterface::class);

        return $storage;
    }
}
