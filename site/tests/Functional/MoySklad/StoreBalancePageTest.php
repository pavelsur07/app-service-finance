<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MoySklad\MoySkladStoreBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Страница «Склады»: назначение счёта Баланса складу и настройки записи (docs/tasks/moysklad-store-balance/plan.md). */
final class StoreBalancePageTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testAssignsAssetAccountToStore(): void
    {
        [$client, $connectionId, $store, $accounts] = $this->seed();
        $url = '/moy-sklad/connections/'.$connectionId.'/stores';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-store-balance-book-not-started]');
        self::assertSelectorExists('[data-store="'.$store.'"] select[name="target"] option[value="'.$accounts['stock'].'"]');
        self::assertSelectorNotExists('[data-store="'.$store.'"] select[name="target"] option[value="'.$accounts['equity'].'"]', 'Склад держится только на активе.');
        self::assertSelectorTextContains('[data-moysklad-tabs] [aria-current="page"]', 'Склады');

        $form = $client->getCrawler()->filter('[data-store="'.$store.'"] form')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['target' => $accounts['stock']]));
        self::assertResponseStatusCodeSame(303);
        self::assertSame($accounts['stock'], $this->em()->getConnection()->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$store]));

        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['target' => $accounts['foreign']]));
        self::assertResponseStatusCodeSame(303);
        self::assertSame($accounts['stock'], $this->em()->getConnection()->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$store]), 'Чужой счёт не сохраняется.');

        $client->request('POST', $form->getUri(), ['target' => $accounts['stock'], '_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsRequireCounterAccountAndSaveForOwner(): void
    {
        [$client, $connectionId, , $accounts] = $this->seed();
        $client->request('GET', '/moy-sklad/connections/'.$connectionId.'/stores');
        $form = $client->getCrawler()->filter('[data-store-balance-settings] form')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['counterAccountId' => '', 'enabled' => '1']));
        self::assertResponseStatusCodeSame(303);
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT 1 FROM moysklad_store_balance_settings'));

        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['counterAccountId' => $accounts['equity'], 'enabled' => '1']));
        self::assertResponseStatusCodeSame(303);
        self::assertSame($accounts['equity'], $this->em()->getConnection()->fetchOne('SELECT counter_account_id FROM moysklad_store_balance_settings WHERE enabled'));
    }

    public function testForeignConnectionAndStoreAreNotFound(): void
    {
        [$client, $connectionId, , $accounts] = $this->seed();
        $client->request('GET', '/moy-sklad/connections/'.Uuid::uuid4()->toString().'/stores');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/moy-sklad/connections/'.$connectionId.'/stores');
        $token = $client->getCrawler()->filter('[data-store-balance-settings] input[name="_token"]')->attr('value');
        $client->request('POST', '/moy-sklad/connections/'.$connectionId.'/stores/'.Uuid::uuid4()->toString().'/balance-link', ['_token' => $token, 'target' => $accounts['stock']]);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{KernelBrowser, string, string, array<string, string>} */
    private function seed(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $d = $this->mappingCatalog($this->em());
        $store = MoySkladStoreBuilder::aStore()->build();
        $otherOwner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $other = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($otherOwner)->build();
        foreach ([$store, $otherOwner, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $companyId = $d['connection']->getCompanyId();
        $accounts = [
            'stock' => $this->account($companyId, 'asset', 'Склад офис'),
            'equity' => $this->account($companyId, 'passive', 'Корректировка запасов'),
            'foreign' => $this->account((string) $other->getId(), 'asset', 'Чужой склад'),
        ];
        $this->em()->clear();
        $owner = $d['company']->getUser();
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        return [$client, $d['connection']->getId(), $store->getExternalId(), $accounts];
    }

    private function account(string $companyId, string $type, string $name): string
    {
        $db = $this->em()->getConnection();
        $articleId = Uuid::uuid7()->toString();
        $accountId = Uuid::uuid7()->toString();
        $db->insert('balance_articles', ['id' => $articleId, 'company_id' => $companyId, 'name' => $name.' (статья)', 'type' => $type, 'kind' => 'article', 'level' => 1, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);
        $db->insert('balance_accounts', ['id' => $accountId, 'company_id' => $companyId, 'article_id' => $articleId, 'code' => substr($accountId, -12), 'name' => $name, 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01']);

        return $accountId;
    }
}
