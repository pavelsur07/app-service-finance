<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountStrictMatchTest extends WebTestCaseBase
{
    private const STATEMENT_ACCOUNT = '40802810426140004223';
    private const FIXTURE_NAME = 'Выписка_40802810426140004223_01.05.2026–31.05.2026.txt';

    public function testFixtureAutomaticallySelectsAccountWithoutFormField(): void
    {
        [$client, , $account] = $this->createContext(self::STATEMENT_ACCOUNT);

        $crawler = $client->request('GET', '/cash/import/bank1c');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#money_account_id'));

        $this->uploadFile($client, $this->fixturePath(), self::FIXTURE_NAME);

        self::assertResponseRedirects('/cash/import/bank1c/preview', 303);

        $state = $client->getRequest()->getSession()->get('bank1c_import');
        self::assertIsArray($state);
        self::assertSame($account->getId(), $state['account_id']);
        self::assertSame(self::STATEMENT_ACCOUNT, $state['statement_account']);
        self::assertCount(3, $state['preview']);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Основной счёт', (string) $client->getResponse()->getContent());
    }

    public function testPreviewStopsWhenStatementAccountIsMissing(): void
    {
        [$client] = $this->createContext(self::STATEMENT_ACCOUNT);

        $tmpFile = $this->temporaryStatement(<<<TXT
1CClientBankExchange
ДатаНачала=01.01.2024
ДатаКонца=31.01.2024
КонецФайла
TXT);

        try {
            $this->uploadFile($client, $tmpFile, 'statement.txt');

            self::assertResponseRedirects('/cash/import/bank1c', 303);
            $session = $client->getRequest()->getSession();
            self::assertFalse($session->has('bank1c_import'));
            self::assertStringContainsString(
                'В выписке не указан расчётный счёт',
                $session->getFlashBag()->peek('danger')[0] ?? '',
            );
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testInactiveAndForeignAccountsAreNotDetected(): void
    {
        [$client, $company, $inactiveAccount] = $this->createContext(self::STATEMENT_ACCOUNT, false);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $foreignUser = new User(Uuid::uuid4()->toString());
        $foreignUser->setEmail('foreign-bank-import@example.com');
        $foreignUser->setPassword($hasher->hashPassword($foreignUser, 'password'));
        $foreignCompany = new Company(Uuid::uuid4()->toString(), $foreignUser);
        $foreignCompany->setName('ForeignBankImport');
        $foreignAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $foreignCompany,
            MoneyAccountType::BANK,
            'Чужой счёт',
            'RUB',
        );
        $foreignAccount->setAccountNumber(self::STATEMENT_ACCOUNT);

        $em->persist($foreignUser);
        $em->persist($foreignCompany);
        $em->persist($foreignAccount);
        $em->flush();

        self::assertFalse($inactiveAccount->isActive());
        self::assertNotSame($company->getId(), $foreignCompany->getId());

        $this->uploadFile($client, $this->fixturePath(), self::FIXTURE_NAME);

        self::assertResponseRedirects('/cash/import/bank1c', 303);
        $session = $client->getRequest()->getSession();
        self::assertFalse($session->has('bank1c_import'));
        self::assertStringContainsString(
            'Для выписки не найден активный счёт',
            $session->getFlashBag()->peek('danger')[0] ?? '',
        );
    }

    public function testPreviewWorksWithUtf8EncodedFile(): void
    {
        [$client, , $account] = $this->createContext('40702810900000000001');

        $tmpFile = $this->temporaryStatement(<<<TXT
1CClientBankExchange
РасчСчет=40702810900000000001
ДатаНачала=01.01.2024
ДатаКонца=31.01.2024
СекцияДокумент=Платежное поручение
Номер=1
Дата=15.01.2024
Сумма=1000.00
Плательщик=ООО «Тест»
ПлательщикИНН=1234567890
ПлательщикСчет=40702810900000000001
Получатель=ООО «Поставщик»
ПолучательИНН=0987654321
ПолучательСчет=40702810900000000002
ДатаСписано=15.01.2024
НазначениеПлатежа=Оплата услуг
КонецДокумента
КонецФайла
TXT);

        try {
            $this->uploadFile($client, $tmpFile, 'statement_utf8.txt');

            self::assertResponseRedirects('/cash/import/bank1c/preview', 303);
            $state = $client->getRequest()->getSession()->get('bank1c_import');
            self::assertIsArray($state);
            self::assertSame($account->getId(), $state['account_id']);
        } finally {
            @unlink($tmpFile);
        }
    }

    /** @return array{KernelBrowser, Company, MoneyAccount} */
    private function createContext(string $accountNumber, bool $active = true): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->resetDb();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('bank-import@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('BankImport');

        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Основной счёт',
            'RUB',
        );
        $account->setAccountNumber($accountNumber);
        $account->setIsActive($active);

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $company, $account];
    }

    private function uploadFile(KernelBrowser $client, string $path, string $name): void
    {
        $client->request('POST', '/cash/import/bank1c/preview/upload', [
            '_token' => $this->csrfToken($client, 'bank1c_import_upload'),
        ], [
            'import_file' => new UploadedFile($path, $name, 'text/plain', null, true),
        ]);
    }

    private function temporaryStatement(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bank1c');
        self::assertIsString($tmpFile);
        self::assertNotFalse(file_put_contents($tmpFile, $content));

        return $tmpFile;
    }

    private function fixturePath(): string
    {
        $path = dirname(__DIR__, 3).'/Fixtures/Cash/import/bank1c/'.self::FIXTURE_NAME;
        self::assertFileExists($path);

        return $path;
    }
}
