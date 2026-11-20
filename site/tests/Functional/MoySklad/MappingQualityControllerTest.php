<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\MoySklad\Infrastructure\Query\MappingSettingsQuery;
use App\MoySklad\MessageHandler\DiagnoseMappingsHandler;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MappingQualityControllerTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testWbPresetCsrfIdempotencyScopeAndReadOnlyPermissions(): void
    {
        [$client, $d] = $this->seed();
        $url = '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/wildberries';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-wb-alias-settings]');
        $form = $client->getCrawler()->filter('form[data-wb-alias-settings]')->form();
        $payload = array_replace($form->getPhpValues(), ['enabled' => '1']);
        $client->request('POST', $form->getUri(), array_replace($payload, ['_token' => 'bad']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $form->getUri(), $payload);
        self::assertResponseStatusCodeSame(303);
        $query = self::getContainer()->get(MappingSettingsQuery::class);
        self::assertTrue($query->get($d['connection']->getCompanyId(), $d['connection']->getId(), 'wildberries')->wbTrailingW);
        self::assertFalse($query->get($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon')->wbTrailingW);
        $client->request('POST', $form->getUri(), $payload);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $query->get($d['connection']->getCompanyId(), $d['connection']->getId(), 'wildberries')->version);
        $client->request('POST', str_replace($d['connection']->getId(), Uuid::uuid7()->toString(), $form->getUri()), $payload);
        self::assertResponseStatusCodeSame(404);
        $user = \App\Tests\Builders\Company\UserBuilder::aUser()->withIndex(2)->withEmail('quality-reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $company = $this->em()->getReference(\App\Company\Entity\Company::class, $d['connection']->getCompanyId());
        self::assertInstanceOf(\App\Company\Entity\Company::class, $company);
        $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Reader', ['marketplace' => 'read'], $company);
        $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withCompany($company)->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $d['connection']->getCompanyId());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-wb-alias-settings]');
        self::assertSelectorNotExists('form[data-diagnostic-refresh]');
        $client->request('POST', '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/wildberries/diagnostics', ['runId' => Uuid::uuid7()->toString()]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $form->getUri(), $payload);
        self::assertResponseStatusCodeSame(403);
    }

    public function testDiagnosisEmptyRefreshCsrfCompletedStaleFiltersRunningAndFailureStates(): void
    {
        [$client, $d] = $this->seed();
        $url = '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/ozon';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-diagnostic-empty]');
        $form = $client->getCrawler()->filter('form[data-diagnostic-refresh]')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['_token' => 'bad']));
        self::assertResponseStatusCodeSame(403);
        $client->submit($form);
        self::assertResponseStatusCodeSame(303);
        $transport = self::getContainer()->get('messenger.transport.async_pipeline');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(\App\MoySklad\Message\DiagnoseMappingsMessage::class, $message);
        self::getContainer()->get(DiagnoseMappingsHandler::class)($message);
        $client->followRedirect();
        self::assertSelectorExists('[data-diagnostic-completed]');
        self::assertSelectorTextContains('[data-diagnostic-completed]', 'Проверено');
        self::assertSelectorNotExists('[data-diagnostic-listing-id="'.$d['listing']->getId().'"]', 'Однозначное соответствие не попадает в «Требует внимания».');
        $client->request('GET', $url, ['view' => 'queue', 'diag_manual' => '']);
        self::assertSelectorExists('[data-diagnostic-listing-id="'.$d['listing']->getId().'"]');
        $this->em()->getConnection()->executeStatement("UPDATE marketplace_listings SET name='Набор 3 шт' WHERE id=?", [$d['listing']->getId()]);
        $this->em()->getConnection()->executeStatement("UPDATE moysklad_products SET name='Товар 1 шт' WHERE external_id=?", [$d['product']->getExternalId()]);
        $client->request('GET', $url);
        self::assertSelectorExists('[data-diagnostic-stale]');
        $client->submit($client->getCrawler()->filter('form[data-diagnostic-refresh]')->form());
        $sent = $transport->getSent();
        $latest = $sent[array_key_last($sent)]->getMessage();
        self::assertInstanceOf(\App\MoySklad\Message\DiagnoseMappingsMessage::class, $latest);
        self::getContainer()->get(DiagnoseMappingsHandler::class)($latest);
        $client->request('GET', $url, ['diag_reason' => 'package_review', 'diag_manual' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-diagnostic-listing-id]', 'Проверить комплектацию');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $this->em()->getConnection()->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='running' WHERE id=?", [$latest->runId]);
        $client->request('GET', $url);
        self::assertSelectorExists('[data-diagnostic-running]');
        self::assertSelectorExists('[data-diagnostic-completed]');
        $before = count($transport->getSent());
        $client->submit($client->getCrawler()->filter('form[data-diagnostic-refresh]')->form());
        self::assertResponseStatusCodeSame(303);
        self::assertCount($before, $transport->getSent(), 'An active run must not enqueue another full scan.');
        $this->em()->getConnection()->executeStatement("UPDATE moysklad_mapping_diagnostic_runs SET status='failed', error_reason='diagnostic_failed' WHERE id=?", [$latest->runId]);
        $client->request('GET', $url);
        self::assertSelectorExists('[data-diagnostic-error]');
        self::assertSelectorExists('[data-diagnostic-completed]');
    }

    public function testQueueAndAllListingsHaveTheirOwnPagination(): void
    {
        [$client, $d] = $this->seed();
        foreach (range(2, 26) as $i) {
            $this->em()->persist(\App\Tests\Builders\Marketplace\MarketplaceListingBuilder::aListing()->forCompany($d['company'])->withIndex($i)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->withSupplierSku('001')->build());
        }
        $this->em()->flush();
        self::getContainer()->get(DiagnoseMappingsHandler::class)(new \App\MoySklad\Message\DiagnoseMappingsMessage($d['connection']->getCompanyId(), $d['connection']->getId(), 'ozon', Uuid::uuid7()->toString()));
        $url = '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/ozon';
        $client->request('GET', $url, ['view' => 'queue', 'diag_manual' => '', 'diag_page' => '2', 'page' => '2']);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-diagnostic-listing-id]');
        $back = $client->getCrawler()->filter('[data-mapping-pagination] a[aria-label="Назад"]');
        self::assertStringContainsString('diag_page=1', (string) $back->attr('href'));
        self::assertStringContainsString('view=queue', (string) $back->attr('href'));
        $client->click($back->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '[data-diagnostic-listing-id]');
        $client->request('GET', $url, ['view' => 'all', 'page' => '2', 'diag_page' => '2']);
        self::assertSelectorCount(1, '[data-listing-id]');
        self::assertSelectorNotExists('[data-diagnostic-listing-id]');
        $back = $client->getCrawler()->filter('[data-mapping-pagination] a[aria-label="Назад"]');
        self::assertStringContainsString('view=all', (string) $back->attr('href'));
        $client->click($back->link());
        self::assertSelectorCount(25, '[data-listing-id]');
    }

    public function testRiskyManualMappingRequiresCheckboxAndRejectsStaleForm(): void
    {
        [$client, $d] = $this->seed();
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE marketplace_listings SET name='Набор 5 шт' WHERE id=?", [$d['listing']->getId()]);
        $db->executeStatement("UPDATE moysklad_products SET name='Товар 1 шт' WHERE external_id=?", [$d['product']->getExternalId()]);
        $url = '/moy-sklad/connections/'.$d['connection']->getId().'/mappings/ozon/'.$d['listing']->getId().'/edit';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="packageReviewConfirmed"]');
        $form = $client->getCrawler()->filter('form[data-set-mapping-form]')->form();
        $payload = array_replace($form->getPhpValues(), ['target' => 'product:'.$d['product']->getExternalId()]);
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-moysklad-mapping-form]', 'Проверить комплектацию');
        $db->executeStatement("UPDATE marketplace_listings SET name='Набор 2 шт' WHERE id=?", [$d['listing']->getId()]);
        $client->request('POST', $url, array_replace($payload, ['packageReviewConfirmed' => '1']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Данные комплектации изменились');
        $client->request('GET', $url);
        $payload = array_replace($client->getCrawler()->filter('form[data-set-mapping-form]')->form()->getPhpValues(), ['target' => 'product:'.$d['product']->getExternalId(), 'packageReviewConfirmed' => '1']);
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-listing-id="'.$d['listing']->getId().'"]', 'Сопоставлен');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_mapping_package_reviews'));
    }

    /** @return array{KernelBrowser,array{connection:\App\MoySklad\Entity\MoySkladConnection,product:\App\MoySklad\Entity\MoySkladProduct,listing:\App\Marketplace\Entity\MarketplaceListing,company:\App\Company\Entity\Company,actor:string}} */
    private function seed(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $d = $this->mappingCatalog($this->em());
        $owner = $d['company']->getUser();
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $d['connection']->getCompanyId());

        return [$client, $d];
    }
}
