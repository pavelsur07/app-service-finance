<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Entity\Company;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия #458: удаление компании после онбординга (статьи ДДС, счета, ОПиУ, Баланс)
 * отвечало 500 на FK `companies`. Заглушка не трогает данные и показывает сообщение.
 */
final class CompanyDeleteStubTest extends WebTestCaseBase
{
    public function testDeletingOnboardedCompanyRedirectsWithMessageAndKeepsData(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('stub-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->em()->persist($owner);
        $this->em()->flush();
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/company/new');
        $client->submit($crawler->selectButton('Создать')->form(['company[name]' => 'Компания под удаление']));
        self::assertResponseRedirects('/company/');
        $companyId = (string) $this->em()->getConnection()->fetchOne("SELECT id FROM companies WHERE name = 'Компания под удаление'");
        self::assertGreaterThan(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM cashflow_categories WHERE company_id = ?', [$companyId]));

        $client->request('POST', '/company/'.$companyId.'/delete', ['_token' => $this->csrfToken($client, 'delete'.$companyId)]);

        self::assertResponseRedirects('/company/');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast-body', 'через поддержку');
        $this->em()->clear();
        self::assertInstanceOf(Company::class, $this->em()->find(Company::class, $companyId));
    }

    public function testStrangerGetsNotFound(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('stub-owner2@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $stranger = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('stub-stranger@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $this->em()->persist($owner);
        $this->em()->persist($stranger);
        $this->em()->flush();
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/company/new');
        $client->submit($crawler->selectButton('Создать')->form(['company[name]' => 'Чужая для заглушки']));
        $companyId = (string) $this->em()->getConnection()->fetchOne("SELECT id FROM companies WHERE name = 'Чужая для заглушки'");

        $client->loginUser($stranger);
        $client->request('POST', '/company/'.$companyId.'/delete', ['_token' => $this->csrfToken($client, 'delete'.$companyId)]);

        self::assertResponseStatusCodeSame(404);
    }
}
