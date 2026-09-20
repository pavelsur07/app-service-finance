<?php

declare(strict_types=1);

namespace App\Tests\Functional\MoySklad;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyRole;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Entity\MoySkladSyncCursor;
use App\MoySklad\Entity\MoySkladSyncRun;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Infrastructure\Query\MoySkladCounterpartySyncStatusQuery;
use App\MoySklad\Message\SyncCounterpartiesMessage;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ConnectionsControllerTest extends WebTestCaseBase
{
    public function testEditDoesNotDiscloseStoredToken(): void
    {
        [$client, $connection] = $this->seed();
        $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('stored-sensitive-token', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('stored-refresh-secret', (string) $client->getResponse()->getContent());
    }

    public function testUnverifiedConnectionIsNotShownAsConnected(): void
    {
        [$client] = $this->seed();
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Требуется проверка');
        self::assertSelectorExists('a[href="/moy-sklad/connections"]');
    }

    public function testVerifiedConnectionQueuesCounterpartySyncWithCsrf(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->flush();
        $crawler = $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/moy-sklad/connections/'.$connection->getId().'/sync-counterparties"]'));
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-counterparties', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId()),
        ]);
        self::assertResponseRedirects('/moy-sklad/connections');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(SyncCounterpartiesMessage::class, $message);
        self::assertSame($connection->getCompanyId(), $message->companyId);
        self::assertSame($connection->getId(), $message->connectionId);
        self::assertStringNotContainsString('stored-sensitive-token', (string) $client->getResponse()->getContent());
    }

    public function testSyncPostRequiresCsrfAndVerifiedActiveConnection(): void
    {
        [$client, $connection] = $this->seed();
        $url = '/moy-sklad/connections/'.$connection->getId().'/sync-counterparties';
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);
        $client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId())]);
        self::assertResponseRedirects('/moy-sklad/connections');
        /** @var InMemoryTransport $transport */
        $transport = $client->getContainer()->get('messenger.transport.async_sync');
        self::assertCount(0, $transport->getSent());
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $connection->setIsActive(false);
        $this->em()->flush();
        $client->request('POST', $url, ['_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId())]);
        self::assertResponseRedirects('/moy-sklad/connections');
        self::assertCount(0, $transport->getSent());
    }

    public function testReadMemberAndForeignCompanyCannotQueueSync(): void
    {
        [$client, $connection] = $this->seed();
        $connection->bindAccount('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $connection->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $foreign->bindAccount('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $foreign->recordCheck(ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable());
        $this->em()->persist($foreign);
        $this->em()->flush();

        $client->request('POST', '/moy-sklad/connections/'.$foreign->getId().'/sync-counterparties');
        self::assertResponseStatusCodeSame(404);
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/sync-counterparties"]');
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/sync-counterparties', [
            '_token' => $this->csrfToken($client, 'moysklad_sync_counterparties'.$connection->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testListShowsLastRunCountersAndCompletedCursor(): void
    {
        [$client, $connection] = $this->seed();
        $now = new \DateTimeImmutable('2026-09-20T09:00:00+00:00');
        $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty');
        $cursor->completeAt($now);
        $run = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty', $now);
        $run->recordPage(2, 1, 1, 0);
        $run->succeed($now);
        $this->em()->persist($cursor);
        $this->em()->persist($run);
        $this->em()->flush();

        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Последний полный проход');
        self::assertSelectorTextContains('body', 'Обработано: 2');
        self::assertSelectorTextContains('body', 'Создано: 1');
        self::assertSelectorTextContains('body', 'Обновлено: 1');
        self::assertStringNotContainsString('stored-sensitive-token', (string) $client->getResponse()->getContent());
    }

    public function testFailedRunKeepsPreviousCompletedTimeAndHidesForeignStatus(): void
    {
        [$client, $connection] = $this->seed();
        $previous = new \DateTimeImmutable('2026-09-19T09:00:00+00:00');
        $cursor = new MoySkladSyncCursor(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty');
        $cursor->completeAt($previous);
        $run = new MoySkladSyncRun(Uuid::uuid7()->toString(), $connection->getCompanyId(), $connection->getId(), 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        $run->recordPage(2, 2, 0, 0);
        $run->fail('rate_limited', new \DateTimeImmutable('2026-09-20T09:01:00+00:00'));
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $foreignRun = new MoySkladSyncRun(Uuid::uuid7()->toString(), $foreign->getCompanyId(), $foreign->getId(), 'counterparty', new \DateTimeImmutable('2026-09-20T09:00:00+00:00'));
        foreach ([$cursor, $run, $foreign, $foreignRun] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $statuses = static::getContainer()->get(MoySkladCounterpartySyncStatusQuery::class)->forConnections($connection->getCompanyId(), [$connection->getId(), $foreign->getId()]);
        self::assertArrayHasKey($connection->getId(), $statuses);
        self::assertArrayNotHasKey($foreign->getId(), $statuses);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Лимит запросов МойСклад');
        self::assertSelectorTextContains('body', '19.09.2026');
        self::assertSelectorTextNotContains('body', 'Чужой склад');
    }

    public function testCheckThenRenameUsesVersionFromRenderedEditForm(): void
    {
        [$client, $connection] = $this->seed();
        $this->postOperation($client, $connection, 'check');
        self::assertTrue($connection->isVerified());
        $crawler = $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/edit');
        $client->submit($crawler->selectButton('Сохранить')->form(['moy_sklad_connection[name]' => 'Новое название']));
        self::assertResponseRedirects('/moy-sklad/connections');
        self::assertSame('Новое название', $this->em()->getConnection()->fetchOne('SELECT name FROM moysklad_connections WHERE id = ?', [$connection->getId()]));
    }

    public function testCreateChecksAccountAndEncryptsSubmittedToken(): void
    {
        [$client] = $this->seed();
        $crawler = $client->request('GET', '/moy-sklad/connections/create');
        $client->submit($crawler->selectButton('Проверить и подключить')->form([
            'moy_sklad_connection[name]' => 'Новый склад',
            'moy_sklad_connection[token]' => 'submitted-secret-token',
        ]));
        self::assertResponseRedirects('/moy-sklad/connections');
        $row = $this->em()->getConnection()->fetchAssociative('SELECT account_id, access_token, access_token_encrypted FROM moysklad_connections WHERE name = ?', ['Новый склад']);
        self::assertIsArray($row);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $row['account_id']);
        self::assertNull($row['access_token']);
        self::assertStringNotContainsString('submitted-secret-token', $row['access_token_encrypted']);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Подключено');
        self::assertStringNotContainsString('submitted-secret-token', (string) $client->getResponse()->getContent());
    }

    public function testRejectedTokenKeepsNameAndNeverRepopulatesPassword(): void
    {
        [$client] = $this->seed(401);
        $crawler = $client->request('GET', '/moy-sklad/connections/create');
        $client->submit($crawler->selectButton('Проверить и подключить')->form([
            'moy_sklad_connection[name]' => 'Сохранить название',
            'moy_sklad_connection[token]' => 'rejected-secret-token',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('moy_sklad_connection[name]', 'Сохранить название');
        self::assertInputValueSame('moy_sklad_connection[token]', '');
        self::assertStringNotContainsString('rejected-secret-token', (string) $client->getResponse()->getContent());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_connections'));
    }

    public function testReadMemberCanListButCannotMutate(): void
    {
        [$client, $connection] = $this->seed();
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/moy-sklad/connections/create"]');
        self::assertSelectorNotExists('form[action$="/replace-token"]');
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/disable', [
            '_token' => $this->csrfToken($client, 'moysklad_disable'.$connection->getId()),
            'version' => $connection->getVersion(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReadMemberCanOpenFormsButCannotSave(): void
    {
        [$client, $connection] = $this->seed();
        $this->loginMember($client, $connection->getCompanyId(), ['marketplace' => 'read']);
        $crawler = $client->request('GET', '/moy-sklad/connections/create');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Проверить и подключить')->form([
            'moy_sklad_connection[name]' => 'Запрещённое создание',
            'moy_sklad_connection[token]' => 'read-member-token',
        ]));
        self::assertResponseStatusCodeSame(403);
        $crawler = $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Сохранить')->form(['moy_sklad_connection[name]' => 'Запрещённое изменение']));
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_connections'));
        self::assertSame($connection->getName(), $this->em()->getConnection()->fetchOne('SELECT name FROM moysklad_connections WHERE id = ?', [$connection->getId()]));
    }

    public function testMemberWithoutReadCannotList(): void
    {
        [$client, $connection] = $this->seed();
        $this->loginMember($client, $connection->getCompanyId(), []);
        $client->request('GET', '/moy-sklad/connections');
        self::assertResponseStatusCodeSame(403);
    }

    public function testForeignCompanyConnectionIsAbsentAndCannotBeEditedOrDisabled(): void
    {
        [$client, $connection] = $this->seed();
        $foreign = new MoySkladConnection(Uuid::uuid7()->toString(), Uuid::uuid7()->toString(), 'Чужой склад', $connection->getBaseUrl());
        $this->em()->persist($foreign);
        $this->em()->flush();
        $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorTextNotContains('body', 'Чужой склад');
        $client->request('GET', '/moy-sklad/connections/'.$foreign->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/moy-sklad/connections/'.$foreign->getId().'/disable');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMutationsRejectGetAndInvalidCsrf(): void
    {
        [$client, $connection] = $this->seed();
        foreach (['check', 'replace-token', 'disable', 'enable', 'delete'] as $operation) {
            $client->request('GET', '/moy-sklad/connections/'.$connection->getId().'/'.$operation);
            self::assertResponseStatusCodeSame(405);
            $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/'.$operation, ['_token' => 'invalid', 'version' => 1]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testDisableThenEnableChecksAccessBeforeReactivating(): void
    {
        [$client, $connection] = $this->seed();
        $this->postOperation($client, $connection, 'disable');
        self::assertFalse($connection->isActive());
        $this->postOperation($client, $connection, 'enable');
        self::assertTrue($connection->isActive());
        self::assertTrue($connection->isVerified());
    }

    public function testReplacementAndDeleteUseRealPostRoutes(): void
    {
        [$client, $connection] = $this->seed();
        $this->postOperation($client, $connection, 'replace-token', ['token' => 'replacement-secret']);
        self::assertNull($connection->getAccessToken());
        self::assertNotNull($connection->getAccessTokenEncrypted());
        $this->postOperation($client, $connection, 'disable');
        $id = $connection->getId();
        $this->postOperation($client, $connection, 'delete');
        self::assertFalse($this->em()->getConnection()->fetchOne('SELECT id FROM moysklad_connections WHERE id = ?', [$id]));
    }

    public function testPaginationLimitsCardsAndRejectsInvalidPage(): void
    {
        [$client, $connection] = $this->seed();
        for ($index = 1; $index <= 20; ++$index) {
            $this->em()->persist(new MoySkladConnection(Uuid::uuid7()->toString(), $connection->getCompanyId(), 'Склад '.$index, $connection->getBaseUrl()));
        }
        $this->em()->flush();
        $client->request('GET', '/moy-sklad/connections');
        self::assertSelectorCount(20, 'section.card');
        $client->request('GET', '/moy-sklad/connections?page=2');
        self::assertSelectorCount(1, 'section.card');
        $client->request('GET', '/moy-sklad/connections?page=0');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/moy-sklad/connections?page=3');
        self::assertResponseStatusCodeSame(422);
    }

    /** @param array<string, string> $extra */
    private function postOperation(KernelBrowser $client, MoySkladConnection &$connection, string $operation, array $extra = []): void
    {
        $client->request('POST', '/moy-sklad/connections/'.$connection->getId().'/'.$operation, $extra + [
            '_token' => $this->csrfToken($client, 'moysklad_'.$operation.$connection->getId()),
            'version' => $connection->getVersion(),
        ]);
        self::assertResponseRedirects('/moy-sklad/connections');
        if ('delete' !== $operation) {
            $reloaded = $this->em()->find(MoySkladConnection::class, $connection->getId());
            self::assertInstanceOf(MoySkladConnection::class, $reloaded);
            $connection = $reloaded;
        }
    }

    /** @param array<string, string> $permissions */
    private function loginMember(KernelBrowser $client, string $companyId, array $permissions): void
    {
        $company = $this->em()->find(Company::class, $companyId);
        self::assertInstanceOf(Company::class, $company);
        $user = UserBuilder::aUser()->withIndex(2)->withEmail('reader@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $role = new CompanyRole(Uuid::uuid7()->toString(), 'Reader', $permissions, $company);
        $member = CompanyMemberBuilder::aMember()->withCompany($company)->withUser($user)->withAccessRole($role)->build();
        foreach ([$user, $role, $member] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);
    }

    /** @return array{KernelBrowser, MoySkladConnection} */
    private function seed(int $status = 200): array
    {
        $this->resetDb();
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('moysklad.http_client', new MockHttpClient(new MockResponse('{"accountId":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}', ['http_code' => $status])));
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $connection = new MoySkladConnection(Uuid::uuid7()->toString(), (string) $company->getId(), 'Склад', 'https://api.moysklad.ru/api/remap/1.2');
        $connection->setAccessToken('stored-sensitive-token')->setRefreshToken('stored-refresh-secret');
        $this->em()->persist($connection);
        $this->em()->flush();
        $limiter = static::getContainer()->get('limiter.moysklad_connection_check');
        $limiter->create($company->getId().':create:'.$user->getId())->reset();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return [$client, $connection];
    }
}
