<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Balance\Application\SeedBalanceStructureAction;
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
        self::assertSelectorExists('[data-store-balance-settings][open]', 'Не настроено — блок настроек раскрыт.');
        self::assertSelectorExists('[data-store="'.$store.'"] [data-store-status="todo"]');
        self::assertSelectorExists('[data-store="'.$store.'"] select option[value="'.$accounts['stock'].'"]');
        self::assertSelectorNotExists('[data-store="'.$store.'"] select option[value="'.$accounts['equity'].'"]', 'Склад держится только на активе.');
        self::assertSelectorTextContains('[data-store-filter="todo"][aria-current="page"]', '1');
        self::assertSelectorTextContains('[data-moysklad-tabs] [aria-current="page"]', 'Склады');
        $client->request('GET', '/moy-sklad/stores');
        self::assertResponseRedirects($url);
        $client->request('GET', $url);

        $form = $client->getCrawler()->filter('[data-store-links]')->form();
        $client->request('POST', $form->getUri(), $this->pick($form->getPhpValues(), $store, $accounts['stock']));
        self::assertResponseRedirects($url.'?filter=todo', 303);
        self::assertSame($accounts['stock'], $this->em()->getConnection()->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$store]));

        $client->request('GET', $url);
        self::assertSelectorExists('[data-store-filter="all"][aria-current="page"]', 'Нечего решать — открыты все склады.');
        self::assertSelectorExists('[data-store="'.$store.'"] [data-store-status="linked"]');
        $form = $client->getCrawler()->filter('[data-store-links]')->form();
        $client->request('POST', $form->getUri(), $this->pick($form->getPhpValues(), $store, $accounts['foreign']));
        self::assertResponseStatusCodeSame(303);
        self::assertSame($accounts['stock'], $this->em()->getConnection()->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$store]), 'Чужой счёт не сохраняется.');

        $client->request('POST', $form->getUri(), ['links' => [$store => ['target' => $accounts['stock'], 'was' => '']], '_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPrefillsConfidentSuggestionAndSavesItWithTheForm(): void
    {
        [$client, $connectionId, $plain] = $this->seed();
        $db = $this->em()->getConnection();
        $companyId = (string) $db->fetchOne('SELECT company_id FROM moysklad_connections WHERE id = ?', [$connectionId]);
        $db->executeStatement('DELETE FROM balance_accounts WHERE company_id = ?', [$companyId]);
        $db->executeStatement('DELETE FROM balance_articles WHERE company_id = ?', [$companyId]);
        self::assertTrue(self::getContainer()->get(SeedBalanceStructureAction::class)($companyId));
        $ff = MoySkladStoreBuilder::aStore()->withIndex(7)->withTenant($companyId, $connectionId)->withName('ФФ К2 (РнД)')->build();
        $ozon = MoySkladStoreBuilder::aStore()->withIndex(8)->withTenant($companyId, $connectionId)->withName('Ozon')->build();
        $this->em()->persist($ff);
        $this->em()->persist($ozon);
        $this->em()->flush();
        $this->em()->clear();
        $code = static fn (string $code): string => (string) $db->fetchOne('SELECT id FROM balance_accounts WHERE company_id = ? AND code = ?', [$companyId, $code]);

        $client->request('GET', '/moy-sklad/connections/'.$connectionId.'/stores');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-store="'.$ff->getExternalId().'"] [data-store-suggestion="'.$code('ACC_INV_FF').'"]', 'Склад фулфилмент-оператора');
        self::assertSelectorExists('[data-store="'.$ff->getExternalId().'"] [data-store-status="suggested"]');
        self::assertSelectorExists('[data-store="'.$ff->getExternalId().'"] select option[value="'.$code('ACC_INV_FF').'"][selected]', 'Уверенное предложение подставлено.');
        self::assertSelectorTextContains('[data-store="'.$ff->getExternalId().'"] select optgroup[label="Запасы"]', 'Склады фулфилмента');
        self::assertSelectorTextContains('[data-store="'.$ozon->getExternalId().'"] [data-store-suggestion-warning]', 'в Баланс не проводятся');
        self::assertSelectorNotExists('[data-store="'.$plain.'"] [data-store-suggestion]', 'Склад без признаков — без предложения.');
        self::assertSelectorTextContains('[data-store-links-save]', '2');

        $form = $client->getCrawler()->filter('[data-store-links]')->form();
        $stock = $code('ACC_INV_WAREHOUSE');
        $db->executeStatement('INSERT INTO moysklad_store_balance_links (company_id, connection_id, store_external_id, balance_account_id, excluded, actor_user_id, updated_at) VALUES (?, ?, ?, ?, false, ?, now())',
            [$companyId, $connectionId, $ff->getExternalId(), $stock, (string) $db->fetchOne('SELECT user_id FROM companies WHERE id = ?', [$companyId])]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame($stock, $db->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$ff->getExternalId()]), 'Назначение, сделанное после показа страницы, не перезаписано.');
        self::assertSame($code('ACC_INV_OZON'), $db->fetchOne('SELECT balance_account_id FROM moysklad_store_balance_links WHERE store_external_id = ?', [$ozon->getExternalId()]));
        self::assertFalse($db->fetchOne('SELECT 1 FROM moysklad_store_balance_links WHERE store_external_id = ?', [$plain]));
        $client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Сохранено: 1');
    }

    public function testOpeningPageAddsStandardChartForExistingStructure(): void
    {
        [$client, $connectionId, $store] = $this->seed();
        $companyId = (string) $this->em()->getConnection()->fetchOne('SELECT company_id FROM moysklad_connections WHERE id = ?', [$connectionId]);
        $this->em()->getConnection()->executeStatement('DELETE FROM balance_accounts WHERE company_id = ?', [$companyId]);
        $this->em()->getConnection()->executeStatement('DELETE FROM balance_articles WHERE company_id = ?', [$companyId]);
        self::assertTrue(self::getContainer()->get(SeedBalanceStructureAction::class)($companyId));

        $client->request('GET', '/moy-sklad/connections/'.$connectionId.'/stores');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-store="'.$store.'"] select', 'Склады фулфилмента');
        self::assertSame(28, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM balance_accounts WHERE company_id = ?', [$companyId]));
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
        $client->request('POST', '/moy-sklad/connections/'.Uuid::uuid4()->toString().'/stores/balance-links', ['_token' => $token, 'links' => []]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/moy-sklad/connections/'.$connectionId.'/stores/balance-links', ['_token' => $token, 'links' => [Uuid::uuid4()->toString() => ['target' => $accounts['stock'], 'was' => '']]]);
        self::assertResponseStatusCodeSame(303, 'Чужой или неизвестный склад — отказ по строке, не 500.');
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT 1 FROM moysklad_store_balance_links'));
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

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function pick(array $values, string $store, string $target): array
    {
        $values['links'][$store]['target'] = $target;

        return $values;
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
