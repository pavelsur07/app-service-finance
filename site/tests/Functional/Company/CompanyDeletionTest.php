<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\BankSync\Enum\BankConnectionStatus;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Telegram\Entity\ClientBinding;
use App\Telegram\Entity\ReportSubscription;
use App\Telegram\Entity\TelegramBot;
use App\Telegram\Entity\TelegramUser;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Удаление компании (docs/tasks/company-deletion, этап 1): запрос удаления помечает компанию
 * и отключает подключения, данные остаются; восстановление снимает пометку.
 */
final class CompanyDeletionTest extends WebTestCaseBase
{
    /**
     * Регрессия #458: компания после онбординга (статьи ДДС, счета, дерево ОПиУ, Баланс,
     * пробный тариф) удалялась с 500 из-за FK на `companies`.
     */
    public function testOnboardedCompanyDeletionRedirectsAndKeepsDataWithConnectionsDisabled(): void
    {
        $client = static::createClient();
        $owner = $this->owner();
        $client->loginUser($owner);
        $companyId = $this->createCompanyThroughForm($client, 'Удаляемая компания');
        $keepId = $this->createCompanyThroughForm($client, 'Остающаяся компания');
        $this->addConnections($companyId);
        $categoriesBefore = $this->rowCount('cashflow_categories', $companyId);
        self::assertGreaterThan(0, $categoriesBefore);

        $client->request('GET', '/company/'.$companyId.'/delete');
        self::assertResponseIsSuccessful();
        $client->submitForm('Удалить компанию', ['confirm_name' => '  удаляемая   КОМПАНИЯ ']);

        self::assertResponseRedirects('/company/');
        $company = $this->reloadCompany($companyId);
        self::assertTrue($company->isDeletionRequested());
        self::assertSame($owner->getId(), $company->getDeletionRequestedBy());
        self::assertSame($categoriesBefore, $this->rowCount('cashflow_categories', $companyId));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM marketplace_connections WHERE company_id = ? AND is_active', [$companyId]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM moysklad_connections WHERE company_id = ? AND is_active', [$companyId]));
        self::assertSame([BankConnectionStatus::DISABLED->value], $this->em()->getConnection()->fetchFirstColumn('SELECT status FROM bank_sync_connection WHERE company_id = ?', [$companyId]));
        self::assertSame([ClientBinding::STATUS_BLOCKED], $this->em()->getConnection()->fetchFirstColumn('SELECT status FROM client_bindings WHERE company_id = ?', [$companyId]));
        self::assertSame([false], $this->em()->getConnection()->fetchFirstColumn('SELECT is_enabled FROM report_subscriptions WHERE company_id = ?', [$companyId]));

        // Список: компания в блоке «Будут удалены», в переключателе её нет.
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'будет удалена '.$company->getDeletionDueAt()?->format('d.m.Y'));
        self::assertCount(0, $crawler->filter('select[name="company_id"] option[value="'.$companyId.'"]'));
        self::assertCount(1, $crawler->filter('select[name="company_id"] option[value="'.$keepId.'"]'));
        self::assertCount(1, $crawler->filter('form[action="/company/'.$companyId.'/restore"]'));

        // Активной помеченная компания не становится, даже если осталась в сессии.
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
        $client->request('GET', '/company/');
        self::assertResponseIsSuccessful();
        self::assertSame($keepId, $client->getRequest()->getSession()->get('active_company_id'));

        $client->request('POST', '/company/active', ['company_id' => $companyId]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWrongConfirmationNameKeepsCompany(): void
    {
        $client = static::createClient();
        $client->loginUser($this->owner());
        $companyId = $this->createCompanyThroughForm($client, 'Компания А');

        $client->request('GET', '/company/'.$companyId.'/delete');
        $client->submitForm('Удалить компанию', ['confirm_name' => 'Компания Б']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Название не совпадает');
        self::assertFalse($this->reloadCompany($companyId)->isDeletionRequested());
    }

    public function testNonOwnerGetsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->owner());
        $companyId = $this->createCompanyThroughForm($client, 'Чужая компания');

        $stranger = $this->owner();
        $client->loginUser($stranger);
        $client->request('GET', '/company/'.$companyId.'/delete');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/company/'.$companyId.'/delete', ['_token' => $this->csrfToken($client, 'delete'.$companyId), 'confirm_name' => 'Чужая компания']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/company/'.$companyId.'/restore', ['_token' => $this->csrfToken($client, 'restore'.$companyId)]);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse($this->reloadCompany($companyId)->isDeletionRequested());
    }

    public function testRepeatedRequestKeepsStateAndRestoreBringsCompanyBack(): void
    {
        $client = static::createClient();
        $client->loginUser($this->owner());
        $companyId = $this->createCompanyThroughForm($client, 'Компания для повтора');

        $post = fn () => $client->request('POST', '/company/'.$companyId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$companyId),
            'confirm_name' => 'Компания для повтора',
        ]);
        $post();
        self::assertResponseRedirects('/company/');
        $firstAt = $this->reloadCompany($companyId)->getDeletionRequestedAt();

        $post();
        self::assertResponseRedirects('/company/');
        self::assertEquals($firstAt, $this->reloadCompany($companyId)->getDeletionRequestedAt());

        $client->request('POST', '/company/'.$companyId.'/restore', ['_token' => $this->csrfToken($client, 'restore'.$companyId)]);
        self::assertResponseRedirects('/company/');
        self::assertFalse($this->reloadCompany($companyId)->isDeletionRequested());

        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('select[name="company_id"] option[value="'.$companyId.'"]'));
    }

    public function testOwnerWhoseOnlyCompanyIsMarkedStillSeesListAndCanRestore(): void
    {
        $client = static::createClient();
        $client->loginUser($this->owner());
        $onlyId = $this->createCompanyThroughForm($client, 'Единственная компания');
        $client->request('POST', '/company/'.$onlyId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$onlyId),
            'confirm_name' => 'Единственная компания',
        ]);
        self::assertResponseRedirects('/company/');

        $crawler = $client->request('GET', '/company/');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/company/'.$onlyId.'/restore"]'));
        self::assertCount(0, $crawler->filter('select[name="company_id"] option'));
    }

    /** Участник помеченной компании теряет доступ и в межкомпанийных сценариях (импорт статей и т.п.). */
    public function testMemberLosesCrossCompanyAccessToCompanyMarkedForDeletion(): void
    {
        $client = static::createClient();
        $owner = $this->owner();
        $client->loginUser($owner);
        $companyId = $this->createCompanyThroughForm($client, 'Компания с сотрудником');
        $member = $this->owner();
        $em = $this->em();
        $company = $em->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $em->persist(CompanyMemberBuilder::aMember()->withId(Uuid::uuid7()->toString())->withCompany($company)->withUser($member)->build());
        $em->flush();

        /** @var CompanyFacade $facade */
        $facade = $client->getContainer()->get(CompanyFacade::class);
        self::assertTrue($facade->userHasAccess($companyId, (string) $member->getId()));

        $client->request('POST', '/company/'.$companyId.'/delete', [
            '_token' => $this->csrfToken($client, 'delete'.$companyId),
            'confirm_name' => 'Компания с сотрудником',
        ]);
        self::assertResponseRedirects('/company/');
        $this->em()->clear();

        /** @var CompanyFacade $facade */
        $facade = $client->getContainer()->get(CompanyFacade::class);
        self::assertFalse($facade->userHasAccess($companyId, (string) $member->getId()));
        self::assertFalse($facade->userHasAccess($companyId, (string) $owner->getId()));
        self::assertNotContains($companyId, array_column($facade->listAccessibleCompaniesForUser((string) $member->getId()), 'id'));
    }

    private function owner(): User
    {
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid7()->toString())
            ->withEmail(Uuid::uuid7()->toString().'@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $this->em()->persist($owner);
        $this->em()->flush();

        return $owner;
    }

    private function createCompanyThroughForm(KernelBrowser $client, string $name): string
    {
        $crawler = $client->request('GET', '/company/new');
        $client->submit($crawler->selectButton('Создать')->form(['company[name]' => $name]));
        self::assertResponseRedirects('/company/');

        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM companies WHERE name = ? ORDER BY public_id DESC LIMIT 1', [$name]);
        self::assertIsString($id);

        return $id;
    }

    private function addConnections(string $companyId): void
    {
        $em = $this->em();
        $em->clear();
        $company = $em->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $marketplace = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::WILDBERRIES);
        $marketplace->setApiKey('test-key');
        $em->persist($marketplace);
        $em->persist(MoySkladConnectionBuilder::aConnection()->withIndex(7)->withCompanyId($companyId)->build());
        $em->persist(BankConnectionBuilder::aConnection()->withId(Uuid::uuid7()->toString())->withCompanyId($companyId)->build());
        $bot = new TelegramBot(Uuid::uuid7()->toString(), 'test-token-'.Uuid::uuid7()->toString());
        $telegramUser = new TelegramUser(Uuid::uuid7()->toString(), (string) random_int(100000, 999999999));
        $em->persist($bot);
        $em->persist($telegramUser);
        $em->persist(new ClientBinding(Uuid::uuid7()->toString(), $company, $bot, $telegramUser));
        $em->persist(new ReportSubscription(Uuid::uuid7()->toString(), $company, $telegramUser, 'daily', '09:00'));
        $em->flush();
        $em->clear();
    }

    private function reloadCompany(string $companyId): Company
    {
        $this->em()->clear();
        $company = $this->em()->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function rowCount(string $table, string $companyId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE company_id = ?', $table), [$companyId]);
    }
}
