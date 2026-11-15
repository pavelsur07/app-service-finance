<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Enum\ListingMappingOperation;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AutomaticListingMappingsControllerTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testPreviewApplyRetryAndFreshCurrentStateAfterManualClear(): void
    {
        [$client, $data] = $this->seed();
        $client->request('GET', $this->url($data['connection']->getId()));
        self::assertSelectorExists('form[data-automatic-preview]');
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorExists('[data-automatic-mapping-preview]');
        self::assertSelectorCount(1, 'input[name="selectedIds[]"]:checked');
        $form = $client->getCrawler()->filter('form[data-automatic-apply]')->form();
        $uri = $form->getUri();
        $payload = $form->getPhpValues();
        $client->request('POST', $uri, $payload);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-automatic-result]', 'Принято запросов: 1');
        self::assertSelectorTextContains('[data-current-state]', 'Сопоставлен');
        self::getContainer()->get(ChangeListingMappingAction::class)(new ChangeListingMappingCommand($data['connection']->getCompanyId(), $data['actor'], $data['connection']->getId(), $data['listing']->getId(), ListingMappingOperation::CLEAR, null, null, 1, Uuid::uuid7()->toString()));
        $client->request('POST', $uri, $payload);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-automatic-result]', 'Принято запросов: 1');
        self::assertSelectorTextContains('[data-current-state]', 'Не сопоставлен');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testCsrfTamperedSelectionAndChangedSourceAreRejected(): void
    {
        [$client, $data] = $this->seed();
        $client->request('GET', $this->url($data['connection']->getId()));
        $form = $client->getCrawler()->filter('form[data-automatic-preview]')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['_token' => 'bad']));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $form->getUri(), $form->getPhpValues());
        self::assertResponseStatusCodeSame(303);
        $previewUrl = $client->getResponse()->headers->get('Location');
        $client->followRedirect();
        $apply = $client->getCrawler()->filter('form[data-automatic-apply]')->form();
        $payload = $apply->getPhpValues();
        foreach ([['selectedIds' => [Uuid::uuid7()->toString()]], ['selectedIds' => [['bad']]], ['selectedIds' => array_fill(0, 26, $data['listing']->getId())], ['target' => 'forged'], ['_token' => 'bad']] as $change) {
            $client->request('POST', $apply->getUri(), array_replace($payload, $change));
            self::assertResponseStatusCodeSame(isset($change['_token']) ? 403 : 422);
        }
        $this->em()->getConnection()->executeStatement("UPDATE marketplace_listings SET supplier_sku='CHANGED' WHERE id=?", [$data['listing']->getId()]);
        $client->request('POST', $apply->getUri(), $payload);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-automatic-result]', 'Конфликтов: 1');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $client->request('GET', str_replace('ozon', 'wildberries', (string) $previewUrl));
        self::assertResponseStatusCodeSame(404);
    }

    public function testNoCandidatesRemainVisibleAndDisabled(): void
    {
        [$client, $data] = $this->seed(false);
        $client->request('GET', $this->url($data['connection']->getId()));
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorExists('input[type="checkbox"][disabled]');
        self::assertSelectorNotExists('form[data-automatic-apply]');
        self::assertSelectorTextContains('body', 'загрузите товары');
    }

    public function testUnknownPreviewAndCompanySwitchAreSafe404(): void
    {
        [$client, $data] = $this->seed();
        $client->request('GET', $this->url($data['connection']->getId()).'/automatic/'.Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url($data['connection']->getId()));
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        $location = $client->getResponse()->headers->get('Location');
        $owner = $this->em()->getReference(\App\Company\Entity\User::class, $data['actor']);
        self::assertInstanceOf(\App\Company\Entity\User::class, $owner);
        $other = \App\Tests\Builders\Company\CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($other);
        $this->em()->flush();
        $this->setClientSessionValue($client, 'active_company_id', $other->getId());
        $client->request('GET', (string) $location);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCharacteristicChoicesAreBoundedScopedAndPreserveExplicitSelection(): void
    {
        [$client, $data] = $this->seed();
        $chars = [];
        foreach (range(0, 20) as $i) {
            $chars[] = ['id' => sprintf('char-%02d', $i), 'name' => 'Размер', 'value' => 'M'];
        }
        $v = \App\Tests\Builders\MoySklad\MoySkladVariantBuilder::aVariant()->forProduct($data['product'])->build();
        $now = new \DateTimeImmutable();
        $v->applySnapshot(new \App\MoySklad\Domain\VariantSnapshot($v->getExternalId(), $data['product']->getExternalId(), 'M', '', null, null, $chars, false, $now), $now);
        $this->em()->persist($v);
        $this->em()->flush();
        $url = $this->url($data['connection']->getId());
        $client->request('GET', $url);
        self::assertSelectorCount(21, 'select[name="sizeCharacteristicId"] option');
        $form = $client->getCrawler()->filter('form[data-automatic-preview]')->form();
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['sizeCharacteristicId' => 'char-20']));
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $form->getUri(), array_replace($form->getPhpValues(), ['sizeCharacteristicId' => 'foreign']));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url, ['char_page' => 2, 'sizeCharacteristicId' => 'char-00']);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, 'select[name="sizeCharacteristicId"] option');
        self::assertSelectorExists('option[value="char-00"][selected]');
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        self::assertResponseStatusCodeSame(303);
        foreach ([['char_q' => ['bad']], ['char_page' => 0]] as $bad) {
            $client->request('GET', $url, $bad);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testReadOnlyMemberCanPreviewButCannotApply(): void
    {
        [$client, $data] = $this->seed();
        $user = \App\Tests\Builders\Company\UserBuilder::aUser()->withIndex(2)->withEmail('automatic-reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $role = new \App\Company\Entity\CompanyRole(Uuid::uuid7()->toString(), 'Reader', ['marketplace' => 'read'], $data['company']);
        $member = \App\Tests\Builders\Company\CompanyMemberBuilder::aMember()->withCompany($data['company'])->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $e) {
            $this->em()->persist($e);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $data['connection']->getCompanyId());
        $client->request('GET', $this->url($data['connection']->getId()));
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        self::assertResponseStatusCodeSame(303);
        $location = $client->getResponse()->headers->get('Location');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-automatic-apply]');
        $client->request('POST', $location.'/apply', ['_token' => 'bad', 'selectedIds' => [$data['listing']->getId()]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPartialApplyRedirectsWithFreshEntityManagerAndRetryKeepsAcceptedRows(): void
    {
        [$client, $data] = $this->seed();
        foreach (range(2, 3) as $i) {
            $this->em()->persist(\App\Tests\Builders\Marketplace\MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(\App\Marketplace\Enum\MarketplaceType::OZON)->withMarketplaceSku('PARTIAL-'.$i)->withSupplierSku('001')->build());
        }
        $this->em()->flush();
        $client->request('GET', $this->url($data['connection']->getId()));
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        $client->followRedirect();
        $form = $client->getCrawler()->filter('form[data-automatic-apply]')->form();
        $payload = $form->getPhpValues();
        $uri = $form->getUri();
        $failId = $payload['selectedIds'][1];
        $db = $this->em()->getConnection();
        $db->executeStatement("CREATE FUNCTION pg_temp.fail_http_mapping() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.listing_id = '".$failId."'::uuid THEN RAISE EXCEPTION 'Local test'; END IF; RETURN NEW; END; \$\$");
        $db->executeStatement('CREATE TRIGGER fail_http_mapping BEFORE INSERT ON moysklad_listing_mapping_revisions FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_http_mapping()');
        $client->request('POST', $uri, $payload);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $client->enableReboot(); // Real redirect is a fresh request/kernel/EM.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-automatic-result]', 'Ошибок: 1. Не обработано: 1');
        $this->em()->getConnection()->executeStatement('DROP TRIGGER fail_http_mapping ON moysklad_listing_mapping_revisions');
        $client->request('POST', $uri, $payload);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-automatic-result]', 'Принято запросов: 3');
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testResultCacheFailureReportsPossibleCommitWithoutClaimingRollback(): void
    {
        [$client, $data] = $this->seed();
        $pool = self::getContainer()->get('cache.moysklad_automatic_mapping');
        $broken = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $broken->method('getItem')->willReturnCallback($pool->getItem(...));
        $broken->method('save')->willReturnCallback(static fn (\Psr\Cache\CacheItemInterface $item): bool => substr_count($item->getKey(), '.') < 3 && $pool->save($item));
        self::getContainer()->set(\App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage::class,
            new \App\MoySklad\Infrastructure\Cache\AutomaticMappingPreviewStorage($broken, self::getContainer()->get(\Symfony\Component\Lock\LockFactory::class), new \Symfony\Component\Clock\NativeClock()));
        $client->request('GET', $this->url($data['connection']->getId()));
        $client->submit($client->getCrawler()->filter('form[data-automatic-preview]')->form());
        $client->followRedirect();
        $form = $client->getCrawler()->filter('form[data-automatic-apply]')->form();
        $client->request('POST', $form->getUri(), $form->getPhpValues());
        self::assertResponseStatusCodeSame(503);
        self::assertStringContainsString('Некоторые связи могли сохраниться', (string) $client->getResponse()->getContent());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    private function url(string $connection): string
    {
        return '/moy-sklad/connections/'.$connection.'/mappings/ozon';
    }

    /** @return array{KernelBrowser,array{connection:\App\MoySklad\Entity\MoySkladConnection,product:\App\MoySklad\Entity\MoySkladProduct,listing:\App\Marketplace\Entity\MarketplaceListing,company:\App\Company\Entity\Company,actor:string}} */
    private function seed(bool $ready = true): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $data = $this->mappingCatalog($this->em(), $ready);
        $owner = $data['company']->getUser();
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $data['connection']->getCompanyId());

        return [$client, $data];
    }
}
