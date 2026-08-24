<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpdateMarketplaceConnectionApiKeyControllerTest extends WebTestCaseBase
{
    private const WB_SELLER_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testValidOzonKeyUpdatesExistingConnectionWithoutChangingIdentityOrHistory(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $connection->setClientId('ozon-client-id');
        $connection->setIsActive(false);
        $connection->setLastSyncAt(new \DateTimeImmutable('2026-08-20 10:00:00'));
        $connection->setLastSuccessfulSyncAt(new \DateTimeImmutable('2026-08-19 09:00:00'));
        $connection->setLastSyncError('Ключ истёк');
        $connection->setSettings(['project_direction_id' => 'project-id']);
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/v4/product/info/limit', $url);
            self::assertHeaderValue('ozon-client-id', $options, 'client-id');
            self::assertHeaderValue('new-ozon-key', $options, 'api-key');

            return new MockResponse('{}', ['http_code' => 200]);
        }));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => ' new-ozon-key ',
        ]);

        self::assertResponseRedirects('/marketplace/connections');
        $updated = $this->reload($connection);
        self::assertSame($connection->getId(), $updated->getId());
        self::assertSame('ozon-client-id', $updated->getClientId());
        self::assertSame('new-ozon-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($updated));
        self::assertTrue($updated->hasEncryptedApiKey());
        self::assertTrue($updated->isActive());
        self::assertNull($updated->getLastSyncError());
        self::assertSame('2026-08-20 10:00:00', $updated->getLastSyncAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-19 09:00:00', $updated->getLastSuccessfulSyncAt()?->format('Y-m-d H:i:s'));
        self::assertSame(['project_direction_id' => 'project-id'], $updated->getSettings());
    }

    public function testInvalidOzonKeyDoesNotReplaceStoredCredentialsOrState(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $connection->setClientId('ozon-client-id');
        $connection->setIsActive(false);
        $connection->setLastSyncError('Ключ истёк');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(new MockResponse('{"message":"forbidden"}', ['http_code' => 403])));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'invalid-key',
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        $updated = $this->reload($connection);
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($updated));
        self::assertFalse($updated->isActive());
        self::assertSame('Ключ истёк', $updated->getLastSyncError());
    }

    public function testTemporaryOzonFailureDoesNotReplaceStoredKey(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $connection->setClientId('ozon-client-id');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(new MockResponse('', ['http_code' => 500])));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'unverified-key',
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testOzonRateLimitKeepsStoredKeyAndShowsRetryMessage(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $connection->setClientId('ozon-client-id');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(new MockResponse('', ['http_code' => 429])));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'rate-limited-key',
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
        self::assertSame(
            ['Ozon ограничил количество запросов. Повторите позже.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('error'),
        );
    }

    public function testValidWbKeyUpdatesExistingConnection(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::WILDBERRIES);
        $connection->setIsActive(false);
        $connection->setLastSyncError('Ключ истёк');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);
        $newKey = $this->wbToken(self::WB_SELLER_ID, 'new');
        $client->getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url, array $options) use ($newKey): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/ping', $url);
            self::assertHeaderValue($newKey, $options, 'authorization');

            return new MockResponse('{"Status":"OK"}', ['http_code' => 200]);
        }));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => $newKey,
        ]);

        self::assertResponseRedirects('/marketplace/connections');
        $updated = $this->reload($connection);
        self::assertSame($connection->getId(), $updated->getId());
        self::assertSame($newKey, static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($updated));
        self::assertTrue($updated->hasEncryptedApiKey());
        self::assertTrue($updated->isActive());
        self::assertNull($updated->getLastSyncError());
    }

    public function testRejectedWbKeyDoesNotReplaceStoredCredentials(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::WILDBERRIES);
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(new MockResponse('', ['http_code' => 401])));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => $this->wbToken(self::WB_SELLER_ID, 'invalid'),
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame($this->wbToken(self::WB_SELLER_ID, 'old'), static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testTemporaryWbFailureDoesNotReplaceStoredCredentials(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::WILDBERRIES);
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(new MockResponse('', ['http_code' => 500])));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => $this->wbToken(self::WB_SELLER_ID, 'unverified'),
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame($this->wbToken(self::WB_SELLER_ID, 'old'), static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testWbRateLimitDoesNotReplaceStoredCredentials(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::WILDBERRIES);
        $this->loginWithActiveCompany($client, $user, $company);
        $client->getContainer()->set('http_client', new MockHttpClient(new MockResponse('', [
            'http_code' => 429,
            'response_headers' => ['retry-after: 10'],
        ])));

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => $this->wbToken(self::WB_SELLER_ID, 'rate-limited'),
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame($this->wbToken(self::WB_SELLER_ID, 'old'), static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
        self::assertSame(
            ['Wildberries ограничил количество запросов. Повторите позже.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('error'),
        );
    }

    public function testWbKeyFromAnotherSellerDoesNotReplaceStoredCredentials(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::WILDBERRIES);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => $this->wbToken('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'other-seller'),
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame($this->wbToken(self::WB_SELLER_ID, 'old'), static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    #[DataProvider('malformedWbKeyProvider')]
    public function testMalformedWbKeyDoesNotReplaceStoredCredentials(string $apiKey): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::WILDBERRIES);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => $apiKey,
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame($this->wbToken(self::WB_SELLER_ID, 'old'), static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedWbKeyProvider(): iterable
    {
        $jwt = static fn (string $payload): string => 'header.'.rtrim(strtr(base64_encode($payload), '+/', '-_'), '=').'.signature';

        yield 'not a JWT' => ['not-a-jwt'];
        yield 'invalid base64' => ['header.*.signature'];
        yield 'invalid JSON' => [$jwt('not-json')];
        yield 'non-object claims' => [$jwt('"claim"')];
        yield 'missing seller id' => [$jwt('{}')];
        yield 'invalid seller id' => [$jwt('{"sid":"not-a-uuid"}')];
    }

    public function testEmptyKeyDoesNotReplaceStoredCredentials(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => '   ',
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testUpdateRejectsMissingCsrfToken(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', $this->updateUrl($connection), ['api_key' => 'new-key']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateDoesNotAllowConnectionFromAnotherCompany(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $this->em()->persist($otherCompany);
        $this->em()->flush();
        $connection = $this->seedConnection($otherCompany, MarketplaceType::OZON);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'new-key',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testReadOnlyMarketplaceMemberCannotUpdateKey(): void
    {
        $this->resetDb();
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withEmail('api-key-owner@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $role = new CompanyRole(
            '77777777-7777-4777-8777-777777777777',
            'Marketplace read only',
            [Module::MARKETPLACE->value => AccessLevel::READ->value],
            $company,
        );
        $memberUser = UserBuilder::aUser()
            ->withIndex(2)
            ->withEmail('api-key-reader@test.local')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();
        $member = CompanyMemberBuilder::aMember()
            ->withCompany($company)
            ->withUser($memberUser)
            ->withRole(CompanyMember::ROLE_OPERATOR)
            ->withAccessRole($role)
            ->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->persist($role);
        $this->em()->persist($memberUser);
        $this->em()->persist($member);
        $this->em()->flush();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $this->loginWithActiveCompany($client, $memberUser, $company);

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'new-key',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testPerformanceConnectionCannotBeUpdatedAndHasNoKeyForm(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE);
        $connection->setClientId('performance-client-id');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', $this->editUrl($connection));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="'.$this->updateUrl($connection).'"]');

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'new-secret',
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testUnsupportedSellerMarketplaceCannotBeUpdatedAndHasNoKeyForm(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::YANDEX_MARKET);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', $this->editUrl($connection));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="'.$this->updateUrl($connection).'"]');

        $client->request('POST', $this->updateUrl($connection), [
            '_token' => $this->csrfToken($client, $this->csrfId($connection)),
            'api_key' => 'new-secret',
        ]);

        self::assertResponseRedirects($this->editUrl($connection));
        self::assertSame('old-api-key', static::getContainer()->get(ConnectionApiKeyCodec::class)->apiKeyFor($this->reload($connection)));
    }

    public function testEditFormDoesNotExposeCurrentKeyAndKeepsOzonClientIdReadonly(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $connection->setApiKey('secret-that-must-not-be-rendered');
        $connection->setClientId('ozon-client-id');
        $this->em()->flush();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', $this->editUrl($connection));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="'.$this->updateUrl($connection).'"] input[name="api_key"][type="password"]:not([value])');
        self::assertSelectorExists('input[readonly][value="ozon-client-id"]');
        self::assertStringNotContainsString('secret-that-must-not-be-rendered', (string) $client->getResponse()->getContent());
    }

    public function testUpdateIsNotAllowedViaGet(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $connection = $this->seedConnection($company, MarketplaceType::OZON);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', $this->updateUrl($connection));

        self::assertResponseStatusCodeSame(405);
    }

    public function testMalformedConnectionIdDoesNotReachDatabaseQuery(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/connection/not-a-uuid/api-key');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{User, Company} */
    private function seedBaseData(): array
    {
        $user = UserBuilder::aUser()->withEmail('marketplace-api-key-update@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$user, $company];
    }

    private function seedConnection(
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceConnectionType $connectionType = MarketplaceConnectionType::SELLER,
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            $marketplace,
            $connectionType,
        );
        $connection->setApiKey(
            MarketplaceType::WILDBERRIES === $marketplace
                ? $this->wbToken(self::WB_SELLER_ID, 'old')
                : 'old-api-key',
        );
        $this->em()->persist($connection);
        $this->em()->flush();

        return $connection;
    }

    private function reload(MarketplaceConnection $connection): MarketplaceConnection
    {
        $id = $connection->getId();
        $this->em()->clear();

        return static::getContainer()->get(MarketplaceConnectionRepository::class)->find($id)
            ?? throw new \RuntimeException('Connection was not found after reload.');
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    private function updateUrl(MarketplaceConnection $connection): string
    {
        return sprintf('/marketplace/connection/%s/api-key', $connection->getId());
    }

    private function editUrl(MarketplaceConnection $connection): string
    {
        return sprintf('/marketplace/connection/%s/edit', $connection->getId());
    }

    private function csrfId(MarketplaceConnection $connection): string
    {
        return 'marketplace_connection_api_key_update'.$connection->getId();
    }

    private function wbToken(string $sellerId, string $tokenId): string
    {
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT'])
            .'.'.$encode(['sid' => $sellerId, 'id' => $tokenId])
            .'.signature';
    }

    /** @param array<string, mixed> $options */
    private static function assertHeaderValue(string $expected, array $options, string $normalizedName): void
    {
        $normalized = $options['normalized_headers'][$normalizedName][0] ?? null;
        self::assertIsString($normalized);
        self::assertSame($expected, trim((string) preg_replace('/^[^:]+:/', '', $normalized)));
    }
}
