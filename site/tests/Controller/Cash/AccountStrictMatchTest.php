<?php

namespace App\Tests\Controller\Cash;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AccountStrictMatchTest extends WebTestCase
{
    public function testPreviewStopsWhenStatementAccountDiffersFromSelectedAccount(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $em->createQuery('DELETE FROM App\\Entity\\CashTransaction t')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Counterparty c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\MoneyAccount a')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\Company c')->execute();
        $em->createQuery('DELETE FROM App\\Entity\\User u')->execute();

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('bank-import@example.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('BankImport');

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Основной счёт', 'RUB');
        $account->setAccountNumber('40702810900000000001');

        $em->persist($user);
        $em->persist($company);
        $em->persist($account);
        $em->flush();

        $client->loginUser($user);
        $session = $container->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();

        $csrfManager = $container->get('security.csrf.token_manager');
        $token = $csrfManager->getToken('bank1c_import_upload')->getValue();

        $utf8Content = <<<TXT
1CClientBankExchange
РасчСчет=40702810900000000002
ДатаНачала=01.01.2024
ДатаКонца=31.01.2024
КонецФайла
TXT;
        $cpContent = mb_convert_encoding($utf8Content, 'CP1251', 'UTF-8');
        $tmpFile = tempnam(sys_get_temp_dir(), 'bank1c');
        file_put_contents($tmpFile, $cpContent);

        $uploadedFile = new UploadedFile($tmpFile, 'statement.txt', 'text/plain', null, true);

        $client->request('POST', '/cash/import/bank1c/preview', [
            'money_account_id' => $account->getId(),
            '_token' => $token,
        ], [
            'import_file' => $uploadedFile,
        ]);

        self::assertTrue($client->getResponse()->isRedirect('/cash/import/bank1c'));

        $responseSession = $client->getRequest()->getSession();
        $flashes = $responseSession->getFlashBag()->peek('danger');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString('Выбран неверный банк или выписка', $flashes[0]);
        self::assertFalse($responseSession->has('bank1c_import'));

        @unlink($tmpFile);
    }
}
