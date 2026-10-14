<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Service\Storage\ObjectStorageException;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Сбой записи файла импорта в хранилище: ожидаемый сбой хранилища показывается
 * пользователю flash-сообщением, ошибка программы не глотается.
 */
final class CashFileImportStorageFailureTest extends WebTestCaseBase
{
    public function testStorageFailureRedirectsBackWithFlash(): void
    {
        [$client, $account] = $this->createContext();
        $this->replaceStorage($client, new ObjectStorageException('Failed to write object.', path: 'x'));

        $this->upload($client, $account);

        self::assertResponseRedirects('/cash/import/file');
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['Не удалось сохранить файл импорта.'], $session->getFlashBag()->peek('error'));
    }

    public function testLocalDriverRuntimeFailureRedirectsBackWithFlash(): void
    {
        [$client, $account] = $this->createContext();
        $this->replaceStorage($client, new \RuntimeException('Failed to write storage file.'));

        $this->upload($client, $account);

        self::assertResponseRedirects('/cash/import/file');
    }

    public function testProgrammingErrorIsNotSwallowed(): void
    {
        [$client, $account] = $this->createContext();
        $this->replaceStorage($client, new \LogicException('Programming error.'));

        $this->upload($client, $account);

        self::assertResponseStatusCodeSame(500);
    }

    /**
     * @return array{KernelBrowser, MoneyAccount}
     */
    private function createContext(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail(sprintf('cash-file-import-%s@example.com', Uuid::uuid4()->toString()));
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('CashFileImport');

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, 'Касса', 'RUB');

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $account];
    }

    private function replaceStorage(KernelBrowser $client, \Throwable $writeFailure): void
    {
        $client->getContainer()->set(ObjectStorageInterface::class, new class($writeFailure) implements ObjectStorageInterface {
            public function __construct(private readonly \Throwable $writeFailure)
            {
            }

            public function write(string $path, string $contents): StoredObject
            {
                throw $this->writeFailure;
            }

            public function read(string $path): string
            {
                throw new \LogicException('Not expected in this test.');
            }

            public function readStream(string $path): never
            {
                throw new \LogicException('Not expected in this test.');
            }

            public function exists(string $path): bool
            {
                return false;
            }

            public function delete(string $path): void
            {
            }
        });
    }

    private function upload(KernelBrowser $client, MoneyAccount $account): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cashimport');
        self::assertIsString($file);
        self::assertNotFalse(file_put_contents($file, "date;amount\n2026-01-01;100\n"));

        $client->request('POST', '/cash/import/file/preview/upload', [
            '_token' => $this->csrfToken($client, 'cash_file_import_upload'),
            'money_account_id' => $account->getId(),
        ], [
            'import_file' => new UploadedFile($file, 'import.csv', 'text/csv', null, true),
        ]);
    }
}
