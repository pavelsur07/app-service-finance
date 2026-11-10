<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CashFileImportValidationTest extends WebTestCaseBase
{
    #[DataProvider('incompatibleStates')]
    public function testIncompatibleSessionIsResetBeforeAnyStorageAccess(string $endpoint, string $mode): void
    {
        [$client, $account, $other] = $this->context();
        $storage = $this->storage($client);
        $payload = ['company_id' => $account->getCompany()->getId(), 'account_id' => $account->getId(), 'file_hash' => str_repeat('a', 64), 'stored_ext' => 'csv', 'mapping' => ['date' => 'date', 'amount' => 'amount']];
        if ('legacy' === $mode) {
            unset($payload['company_id']);
        } elseif ('foreign-account' === $mode) {
            $payload['account_id'] = $other->getId();
        } else {
            $this->setClientSessionValue($client, 'active_company_id', $other->getCompany()->getId());
        }
        if ('committed' === $mode) {
            $payload['committed_job_id'] = Uuid::uuid7()->toString();
        }
        $this->setClientSessionValue($client, 'cash_file_import', $payload);
        $client->request('commit' === $endpoint ? 'POST' : 'GET', '/cash/import/file/'.$endpoint, 'commit' === $endpoint ? ['_token' => $this->csrfToken($client, 'cash_file_import_commit')] : []);
        self::assertSame('/cash/import/file', $client->getResponse()->headers->get('Location'));
        self::assertSame([], $storage->calls);
        self::assertFalse($client->getRequest()->getSession()->has('cash_file_import'));
    }

    /** @return iterable<array{string, string}> */
    public static function incompatibleStates(): iterable
    {
        foreach (['mapping', 'preview', 'commit'] as $endpoint) {
            foreach (['switch', 'legacy', 'foreign-account', 'committed'] as $mode) {
                yield $endpoint.' '.$mode => [$endpoint, $mode];
            }
        }
    }

    #[DataProvider('invalidUploads')]
    public function testInvalidUploadDoesNotReachStorage(string $filename, string $contents, int $error): void
    {
        [$client, $account] = $this->context();
        $storage = $this->storage($client);
        $path = tempnam(sys_get_temp_dir(), 'cash-validation-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        try {
            $client->request('POST', '/cash/import/file/preview/upload', ['_token' => $this->csrfToken($client, 'cash_file_import_upload'), 'money_account_id' => $account->getId()], ['import_file' => new UploadedFile($path, $filename, null, $error, true)]);
            self::assertSame('/cash/import/file', $client->getResponse()->headers->get('Location'));
            self::assertSame([], $storage->calls);
            self::assertFalse($client->getRequest()->getSession()->has('cash_file_import'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return iterable<array{string, string, int}> */
    public static function invalidUploads(): iterable
    {
        yield 'TXT' => ['statement.txt', "date;amount\n2026-11-01;10", \UPLOAD_ERR_OK];
        yield 'damaged XLSX' => ['statement.xlsx', 'not a ZIP workbook', \UPLOAD_ERR_OK];
        yield 'empty' => ['statement.csv', '', \UPLOAD_ERR_OK];
        yield 'failed upload' => ['statement.csv', 'partial', \UPLOAD_ERR_PARTIAL];
    }

    #[DataProvider('invalidMappings')]
    public function testMalformedProfileMappingIsRejected(string $mapping): void
    {
        [$client, $account] = $this->context();
        $client->request('POST', '/cash/import/file/profiles/new', ['_token' => $this->csrfToken($client, 'cash_file_import_profile_form'), 'name' => 'Draft', 'mapping' => $mapping, 'options' => '{}']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cash_file_import_profile WHERE company_id = :id', ['id' => $account->getCompany()->getId()]));
    }

    /** @return iterable<array{string}> */
    public static function invalidMappings(): iterable
    {
        yield 'nested array' => ['{"date":[]}'];
        yield 'nested object' => ['{"date":{"column":"A"}}'];
        yield 'integer' => ['{"amount":12}'];
        yield 'unknown key' => ['{"unknown":"A"}'];
    }

    public function testIncompleteMappingDraftCanBeSaved(): void
    {
        [$client, $account] = $this->context();
        $this->setClientSessionValue($client, 'cash_file_import', ['company_id' => $account->getCompany()->getId(), 'account_id' => $account->getId(), 'file_hash' => str_repeat('a', 64)]);
        $client->request('POST', '/cash/import/file/mapping/profile/save', ['_token' => $this->csrfToken($client, 'cash_file_import_mapping'), 'profile_name' => 'Partial', 'description_column' => 'Purpose']);
        self::assertSame('/cash/import/file/mapping', $client->getResponse()->headers->get('Location'));
        $profile = $this->em()->getRepository(CashFileImportProfile::class)->findOneBy(['company' => $account->getCompany(), 'name' => 'Partial']);
        self::assertInstanceOf(CashFileImportProfile::class, $profile);
        self::assertSame('Purpose', $profile->getMapping()['description']);
        self::assertNull($profile->getMapping()['date']);
    }

    #[DataProvider('invalidDates')]
    public function testBadJournalDatesReturnFilterError(string $from, string $to): void
    {
        [$client] = $this->context();
        $client->request('GET', '/import-logs', ['date_from' => $from, 'date_to' => $to]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /** @return iterable<array{string, string}> */
    public static function invalidDates(): iterable
    {
        yield 'text' => ['not-a-date', ''];
        yield 'impossible' => ['2026-02-30', ''];
        yield 'year zero' => ['0000-01-01', ''];
        yield 'zero byte' => ["2026-11-01\0", ''];
        yield 'reversed' => ['2026-11-10', '2026-11-01'];
    }

    public function testApplyingMalformedStoredProfileDoesNotReplaceMapping(): void
    {
        [$client, $account] = $this->context();
        $profile = new CashFileImportProfile(Uuid::uuid7()->toString(), $account->getCompany(), 'Legacy malformed', ['date' => []]);
        $this->em()->persist($profile);
        $this->em()->flush();
        $this->setClientSessionValue($client, 'cash_file_import', ['company_id' => $account->getCompany()->getId(), 'account_id' => $account->getId(), 'mapping' => ['date' => 'Date']]);
        $client->request('POST', '/cash/import/file/mapping/profile/apply', ['_token' => $this->csrfToken($client, 'cash_file_import_profile_apply'), 'profile_id' => $profile->getId()]);
        self::assertSame('/cash/import/file/mapping', $client->getResponse()->headers->get('Location'));
        $payload = $client->getRequest()->getSession()->get('cash_file_import');
        self::assertSame(['date' => 'Date'], $payload['mapping']);
    }

    #[DataProvider('formats')]
    public function testSupportedUploadKeepsCompanyAndRendersMapping(string $extension): void
    {
        [$client, $account] = $this->context();
        $path = tempnam(sys_get_temp_dir(), 'cash-format-');
        self::assertIsString($path);
        if ('csv' === $extension) {
            file_put_contents($path, "date;amount\n2026-11-01;100\n");
        } else {
            $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet->getActiveSheet()->fromArray([['date', 'amount'], ['2026-11-01', '100']]);
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($sheet, 'xls' === $extension ? 'Xls' : 'Xlsx')->save($path);
            $sheet->disconnectWorksheets();
        }
        $payload = null;
        try {
            $client->request('POST', '/cash/import/file/preview/upload', ['_token' => $this->csrfToken($client, 'cash_file_import_upload'), 'money_account_id' => $account->getId()], ['import_file' => new UploadedFile($path, 'statement.'.$extension, null, \UPLOAD_ERR_OK, true)]);
            self::assertSame('/cash/import/file/mapping', $client->getResponse()->headers->get('Location'));
            $payload = $client->getRequest()->getSession()->get('cash_file_import');
            self::assertSame($account->getCompany()->getId(), $payload['company_id']);
            $client->followRedirect();
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSelectorExists('select[name="date_column"]');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_array($payload)) {
                $client->getContainer()->get(ObjectStorageInterface::class)->delete('cash-file-imports/'.$payload['file_hash'].'.'.$extension);
            }
        }
    }

    /** @return iterable<array{string}> */
    public static function formats(): iterable
    {
        yield ['csv'];
        yield ['xls'];
        yield ['xlsx'];
    }

    public function testUploadOver32MiBIsRejected(): void
    {
        [$client, $account] = $this->context();
        $storage = $this->storage($client);
        $path = tempnam(sys_get_temp_dir(), 'cash-large-');
        self::assertIsString($path);
        $file = fopen($path, 'w');
        self::assertIsResource($file);
        fwrite($file, "date;amount\n");
        $block = str_repeat("2026-11-01;100\n", 4096);
        while (ftell($file) <= 32 * 1024 * 1024) {
            fwrite($file, $block);
        }
        fclose($file);
        try {
            try {
                static::getContainer()->get(\App\Cash\Application\UploadCashFileImportAction::class)($account, $path, 'statement.csv', true);
                self::fail('The server-side Action must enforce 32 MiB independently of PHP upload limits.');
            } catch (\App\Cash\Exception\InvalidCashImportFileException) {
                self::assertSame([], $storage->calls);
            }
        } finally {
            unlink($path);
        }
    }

    /** @return array{KernelBrowser, MoneyAccount, MoneyAccount} */
    private function context(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build();
        $other = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($otherCompany)->build();
        foreach ([$owner, $company, $otherCompany, $account, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $account, $other];
    }

    private function storage(KernelBrowser $client): CashImportStorageSpy
    {
        $storage = new CashImportStorageSpy();
        $client->getContainer()->set(ObjectStorageInterface::class, $storage);

        return $storage;
    }
}

final class CashImportStorageSpy implements ObjectStorageInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function exists(string $path): bool
    {
        $this->calls[] = 'exists';

        return false;
    }

    public function write(string $path, string $contents): StoredObject
    {
        $this->calls[] = 'write';

        return new StoredObject($path, strlen($contents));
    }

    public function read(string $path): string
    {
        $this->calls[] = 'read';

        return '';
    }

    public function readStream(string $path): never
    {
        $this->calls[] = 'readStream';
        throw new \LogicException('Unexpected storage read.');
    }

    public function delete(string $path): void
    {
        $this->calls[] = 'delete';
    }
}
