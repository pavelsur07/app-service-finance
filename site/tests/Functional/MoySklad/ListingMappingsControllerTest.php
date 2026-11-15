<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Company\Entity\CompanyRole;
use App\Marketplace\Enum\MarketplaceType;
use App\MoySklad\Application\Action\ChangeListingMappingAction;
use App\MoySklad\Application\Command\ChangeListingMappingCommand;
use App\MoySklad\Enum\ListingMappingOperation;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use App\Tests\Builders\MoySklad\MoySkladProductBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use App\Tests\Support\MoySklad\MappingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ListingMappingsControllerTest extends WebTestCaseBase
{
    use MappingFixtures;

    public function testSeparateLegacyTabsFilterProvidersAndPreserveConnection(): void
    {
        [$client, $data] = $this->seed();
        $wb = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex(2)->withMarketplace(MarketplaceType::WILDBERRIES)->withMarketplaceSku('WB-001')->withName('Листинг WB')->build();
        $wb->setSize('M');
        $this->em()->persist($wb);
        $this->em()->flush();
        $base = '/moy-sklad/connections/'.$data['connection']->getId().'/mappings/';
        $client->request('GET', $base.'ozon');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-mapping-provider="ozon"]');
        self::assertSelectorExists('[data-listing-id="'.$data['listing']->getId().'"]');
        self::assertSelectorNotExists('[data-listing-id="'.$wb->getId().'"]');
        self::assertSelectorExists('[data-moysklad-tabs] a[href="'.$base.'wildberries"]');
        self::assertSelectorTextContains('[data-moysklad-tabs] [aria-current="page"]', 'Маппинг Ozon');
        $client->request('GET', $base.'wildberries', ['q' => []]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-listing-id="'.$wb->getId().'"]');
        self::assertSelectorNotExists('[data-listing-id="'.$data['listing']->getId().'"]');
        self::assertSelectorTextContains('body', 'Маппинг Wildberries');
        $client->request('GET', $base.'ozon/'.$wb->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $base.'ozon/'.$wb->getId().'/history');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEntryHandlesZeroOneAndMultipleConnectionsAndExistingPagesHaveTabs(): void
    {
        [$client, $data] = $this->seed();
        $entry = '/moy-sklad/mappings/ozon';
        $client->request('GET', $entry);
        self::assertResponseRedirects('/moy-sklad/connections/'.$data['connection']->getId().'/mappings/ozon', 302);
        $other = MoySkladConnectionBuilder::aConnection()->withIndex(2)->build();
        $other->setName('Второе подключение');
        $this->em()->persist($other);
        $this->em()->flush();
        $client->request('GET', $entry);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-moysklad-connection-picker]');
        self::assertSelectorTextContains('body', 'Второе подключение');
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-moysklad-tabs] a[href="/moy-sklad/mappings/ozon"]');
        self::assertSelectorExists('[data-moysklad-tabs] a[href="/moy-sklad/mappings/wildberries"]');
        foreach (['moysklad_products', 'moysklad_sync_cursors', 'moysklad_connections'] as $table) {
            $this->em()->getConnection()->executeStatement('DELETE FROM '.$table.' WHERE company_id=?', [$data['connection']->getCompanyId()]);
        }
        $client->request('GET', $entry);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-moysklad-empty-connections]');
        $client->request('GET', '/moy-sklad/connections/create');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-moysklad-tabs]');
    }

    public function testSuggestionCanBeSavedReplayedAndClearedWithoutChangingCatalogMapping(): void
    {
        [$client, $data] = $this->seed();
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-moysklad-mapping-form]');
        self::assertSelectorTextContains('body', 'Товар');
        $payload = $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId());
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-listing-id="'.$data['listing']->getId().'"]', 'Сопоставлен');
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), null, '1'));
        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-listing-id="'.$data['listing']->getId().'"]', 'Не сопоставлен');
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT product_id FROM marketplace_listings WHERE id=?', [$data['listing']->getId()]));
    }

    public function testAcceptedPostReplaysAfterReplacementAndArchiveAndRejectsChangedPayload(): void
    {
        [$client, $data] = $this->seed();
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $original = $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId());
        $client->request('POST', $url, $original);
        self::assertResponseStatusCodeSame(303);
        $next = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->build();
        $this->em()->persist($next);
        $this->em()->flush();
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $next->getExternalId(), '1'));
        self::assertResponseStatusCodeSame(303);
        $this->em()->getConnection()->executeStatement('UPDATE moysklad_products SET archived=TRUE WHERE external_id=?', [$data['product']->getExternalId()]);
        $client->request('POST', $url, $original);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        self::assertSame($next->getExternalId(), $this->em()->getConnection()->fetchOne('SELECT product_external_id FROM moysklad_listing_mapping_revisions ORDER BY revision DESC LIMIT 1'));
        $client->request('POST', $url, array_replace($original, ['target' => 'product:'.$next->getExternalId()]));
        self::assertResponseStatusCodeSame(409);
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidPosts')]
    public function testCsrfValidationAndConflictsHaveExplicitStatuses(array $changes, int $status): void
    {
        [$client, $data] = $this->seed();
        $payload = array_replace($this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId()), $changes);
        $client->request('POST', $this->editUrl($data['connection']->getId(), $data['listing']->getId()), $payload);
        self::assertResponseStatusCodeSame($status);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    /** @return iterable<string,array{array<string,mixed>,int}> */
    public static function invalidPosts(): iterable
    {
        yield 'CSRF first' => [['_token' => 'invalid', 'target' => 'product:99999999-9999-4999-8999-999999999999'], 403];
        yield 'unknown target' => [['target' => 'product:99999999-9999-4999-8999-999999999999'], 404];
        yield 'invalid target UUID' => [['target' => 'product:invalid'], 422];
        yield 'stale revision' => [['expectedRevision' => '1'], 409];
        yield 'query array' => [['q' => ['invalid']], 422];
    }

    public function testOwnTargetOutsideChoicesIs422AndForeignTargetIs404(): void
    {
        [$client, $data] = $this->seed(debug: false);
        $hidden = MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex(2)->withArticle('OTHER')->build();
        $other = MoySkladConnectionBuilder::aConnection()->withIndex(2)->withCompanyId('99999999-9999-4999-8999-999999999999')->build();
        $foreign = MoySkladProductBuilder::aProduct()->forConnection($other)->withIndex(3)->withName('Foreign secret name')->build();
        foreach ([$hidden, $other, $foreign] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $hidden->getExternalId()));
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $foreign->getExternalId()));
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Foreign secret name', (string) $client->getResponse()->getContent());
        $client->request('GET', '/moy-sklad/connections/'.$other->getId().'/mappings/ozon');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReadOnlyMemberCannotAssignAndEmptyCatalogHasActionableState(): void
    {
        [$client, $data] = $this->seed(false);
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-catalog-not-ready]');
        $user = UserBuilder::aUser()->withIndex(2)->withEmail('mapping-reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Reader', ['marketplace' => 'read'], $data['company']);
        $member = CompanyMemberBuilder::aMember()->withCompany($data['company'])->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        } $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $data['connection']->getCompanyId());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-save-mapping]');
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingListingStillHasOwnHistoryAndCanBeCleared(): void
    {
        [$client, $data] = $this->seed();
        self::getContainer()->get(ChangeListingMappingAction::class)(new ChangeListingMappingCommand($data['connection']->getCompanyId(), $data['actor'], $data['connection']->getId(), $data['listing']->getId(),
            ListingMappingOperation::SET, 'product', $data['product']->getExternalId(), 0, Uuid::uuid7()->toString()));
        $this->em()->getConnection()->executeStatement('DELETE FROM marketplace_listings WHERE id=?', [$data['listing']->getId()]);
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-listing-unavailable]');
        self::assertSelectorNotExists('[data-save-mapping]');
        $client->request('GET', str_replace('/edit', '/history', $url));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-mapping-history]');
        self::assertSelectorTextContains('body', 'Товар');
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), null, '1'));
        self::assertResponseStatusCodeSame(303);
    }

    private function editUrl(string $connectionId, string $listingId): string
    {
        return '/moy-sklad/connections/'.$connectionId.'/mappings/ozon/'.$listingId.'/edit';
    }

    public function testConflictKeepsOriginalRevisionUntilExplicitRefresh(): void
    {
        [$client, $data] = $this->seed();
        $cid = $data['connection']->getId();
        $lid = $data['listing']->getId();
        $pid = $data['product']->getExternalId();
        $old = $this->payload($client, $cid, $lid, $pid);
        $client->request('POST', $this->editUrl($cid, $lid), $this->payload($client, $cid, $lid, $pid));
        self::assertResponseStatusCodeSame(303);
        $client->request('POST', $this->editUrl($cid, $lid), $old);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('form[data-set-mapping-form] input[name="expectedRevision"][value="0"]');
        self::assertSelectorTextContains('body', 'Связь изменилась');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testTargetAndHistoryPaginationRetainRouteAndFormContext(): void
    {
        [$client, $data] = $this->seed();
        for ($i = 2; $i <= 23; ++$i) {
            $this->em()->persist(MoySkladProductBuilder::aProduct()->forConnection($data['connection'])->withIndex($i)->withName(sprintf('Batch %02d', $i))->withArticle('OTHER')->build());
        }
        $this->em()->flush();
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $client->request('GET', $url, ['q' => 'Batch', 'page' => '2']);
        self::assertResponseIsSuccessful();
        $pid = '44444444-4444-4444-8444-000000000023';
        self::assertSelectorExists('input[name="target"][value="product:'.$pid.'"]');
        $payload = $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $pid);
        $payload['q'] = 'Batch';
        $payload['page'] = '2';
        $client->request('POST', $url, $payload);
        self::assertResponseStatusCodeSame(303);
        $repo = self::getContainer()->get(\App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository::class);
        for ($i = 2; $i <= 21; ++$i) {
            $repo->append(\App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder::aRevision()->forListing($data['listing']->getId())->withRevision($i)->build());
        }
        $this->em()->flush();
        $historyUrl = str_replace('/edit', '/history', $url);
        $client->request('GET', $historyUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(20, '[data-mapping-history] tbody tr');
        self::assertSelectorExists('a[href="'.$historyUrl.'?page=2"]');
        $client->request('GET', $historyUrl, ['page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-mapping-history] tbody tr');
    }

    public function testRegistryPaginationAndQueryCountStayBoundedAt25Rows(): void
    {
        [$client, $data] = $this->seed();
        $repo = self::getContainer()->get(\App\MoySklad\Infrastructure\Repository\MoySkladListingMappingRepository::class);
        $repo->append(\App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder::aRevision()->forListing($data['listing']->getId())->build());
        $this->em()->flush();
        $url = '/moy-sklad/connections/'.$data['connection']->getId().'/mappings/ozon';
        $counter = self::getContainer()->get('doctrine.debug_data_holder');
        $counter->reset();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $first = $this->mappingQueryCount($counter);
        self::assertGreaterThan(0, $first);
        for ($i = 2; $i <= 26; ++$i) {
            $listing = MarketplaceListingBuilder::aListing()->forCompany($data['company'])->withIndex($i)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('OZ-'.$i)->build();
            $this->em()->persist($listing);
            $repo->append(\App\Tests\Builders\MoySklad\MoySkladListingMappingRevisionBuilder::aRevision()->forListing($listing->getId())->build());
        }
        $this->em()->flush();
        $counter->reset();
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '[data-listing-id]');
        self::assertSame($first, $this->mappingQueryCount($counter));
        $client->request('GET', $url, ['page' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '[data-listing-id]');
    }

    private function mappingQueryCount(\Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder $holder): int
    {
        /** @var array<string,list<array{sql:string}>> $debug */
        $debug = $holder->getData();

        return count(array_filter($debug['default'] ?? [], static fn (array $query): bool => 1 === preg_match('/marketplace_listings|moysklad_(listing_mapping_revisions|products|variants)/', $query['sql'])));
    }

    public function testCompanySwitchBetweenGetAndPostRejectsOldConnection(): void
    {
        [$client, $data] = $this->seed();
        $payload = $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId());
        $owner = $data['company']->getUser();
        self::assertNotNull($owner);
        $other = \App\Tests\Builders\Company\CompanyBuilder::aCompany()->withId('99999999-9999-4999-8999-999999999999')->withOwner($owner)->build();
        $this->em()->persist($other);
        $this->em()->flush();
        $this->setClientSessionValue($client, 'active_company_id', '99999999-9999-4999-8999-999999999999');
        $payload['companyId'] = $data['connection']->getCompanyId();
        $client->request('POST', $this->editUrl($data['connection']->getId(), $data['listing']->getId()), $payload);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
    }

    public function testArchiveAfterGetIsRejectedAndInvalidRouteUuidIs404(): void
    {
        [$client, $data] = $this->seed();
        $url = $this->editUrl($data['connection']->getId(), $data['listing']->getId());
        $client->request('GET', $url, ['q' => str_repeat('я', 101)]);
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->executeStatement('UPDATE moysklad_products SET archived = TRUE');
        $client->request('POST', $url, $this->payload($client, $data['connection']->getId(), $data['listing']->getId(), $data['product']->getExternalId()));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_listing_mapping_revisions'));
        $client->request('GET', '/moy-sklad/connections/not-uuid/mappings/ozon');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string,string> */
    private function payload(KernelBrowser $client, string $connectionId, string $listingId, ?string $target, string $expected = '0'): array
    {
        $values = ['operation' => null === $target ? 'clear' : 'set', 'expectedRevision' => $expected, 'requestId' => Uuid::uuid7()->toString(),
            '_token' => $this->csrfToken($client, 'moysklad_listing_mapping'.$connectionId.$listingId), 'q' => '', 'page' => '1'];
        if (null !== $target) {
            $values['target'] = 'product:'.$target;
        }

        return $values;
    }

    /** @return array{KernelBrowser,array{connection:\App\MoySklad\Entity\MoySkladConnection,product:\App\MoySklad\Entity\MoySkladProduct,listing:\App\Marketplace\Entity\MarketplaceListing,company:\App\Company\Entity\Company,actor:string}} */
    private function seed(bool $ready = true, bool $debug = true): array
    {
        $client = static::createClient(['debug' => $debug]);
        $client->disableReboot();
        $data = $this->mappingCatalog($this->em(), $ready);
        $owner = $data['company']->getUser();
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $data['connection']->getCompanyId());

        return [$client, $data];
    }
}
