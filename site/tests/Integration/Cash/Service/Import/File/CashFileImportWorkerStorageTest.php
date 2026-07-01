<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import\File;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Service\Import\File\CashFileImportService;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия PR 3 (S3-миграция, тип C): воркер импорта читает файл через
 * ObjectStorageInterface, а не с локального диска по пути. Это снимает
 * межмашинную зависимость web↔worker.
 */
final class CashFileImportWorkerStorageTest extends IntegrationTestCase
{
    public function testWorkerReadsImportFileFromObjectStorage(): void
    {
        $owner = UserBuilder::aUser()->withEmail('cash-import-worker@example.test')->build();
        $company = CompanyBuilder::aCompany()
            ->withId('44444444-4444-4444-4444-444444444401')
            ->withOwner($owner)
            ->withName('Cash Import Worker Co')
            ->build();
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId('44444444-4444-4444-4444-4444444444a1')
            ->forCompany($company)
            ->withCurrency('RUB')
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $csv = "Дата;Сумма;Назначение\n01.12.2025;1000,50;Оплата услуг\n";
        $fileHash = hash('sha256', $csv);
        $storageKey = sprintf('cash-file-imports/%s.csv', $fileHash);

        /** @var ObjectStorageInterface $storage */
        $storage = self::getContainer()->get(ObjectStorageInterface::class);
        $storage->write($storageKey, $csv);

        $job = new CashFileImportJob(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            'cash:file',
            'bank.csv',
            $fileHash,
            ['date' => 'Дата', 'amount' => 'Сумма', 'description' => 'Назначение'],
            ['stored_ext' => 'csv'],
        );
        $this->em->persist($job);
        $this->em->flush();

        /** @var CashFileImportService $service */
        $service = self::getContainer()->get(CashFileImportService::class);
        $service->import($job);

        $transactions = $this->em->getRepository(CashTransaction::class)->findBy(['company' => $company]);
        self::assertCount(1, $transactions, 'Воркер должен был прочитать файл из хранилища и создать транзакцию.');

        $storage->delete($storageKey);
    }
}
