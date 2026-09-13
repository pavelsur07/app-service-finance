<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\ApiKeySecret;
use App\Api\Entity\ApiKey;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class ApiSettingsControllerTest extends WebTestCaseBase
{
    private KernelBrowser $client;
    private Company $company;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-09-13 12:00:00 UTC');
        static::getContainer()->set(ClockInterface::class, $this->clock);
        $owner = UserBuilder::aUser()->withIndex(9860)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(9860)->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($this->company);
        $this->em()->flush();
        $this->client->loginUser($owner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
    }

    public function testEmptyListAndPaginationValidation(): void
    {
        $this->client->request('GET', '/settings/api');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API');
        self::assertSelectorTextContains('.empty-title', 'API-ключей пока нет');
        self::assertStringContainsString((string) $this->company->getPublicId(), (string) $this->client->getResponse()->getContent());
        foreach (['page=0', 'page=abc', 'page=2', 'limit=201', 'limit=0', 'limit=1.5', 'page[]=1'] as $query) {
            $this->client->request('GET', '/settings/api?'.$query);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testCreationIsImmediateOneTimeAndReplayDoesNotIssueAnotherKey(): void
    {
        $crawler = $this->client->request('GET', '/settings/api/create');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Создать ключ')->form(['api_key_name[name]' => '  ERP  ']);
        $values = $form->getPhpValues();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        $token = $this->client->getCrawler()->filter('[data-api-secret]')->attr('value');
        self::assertNotNull($token);
        self::assertNotNull(ApiKeySecret::parse($token));
        self::assertSame(1, $this->keyCount());
        $this->client->request('POST', '/settings/api/create', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->keyCount());
        foreach (['/settings/api', '/settings/api/create'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        }
        self::assertSelectorNotExists('[data-api-secret]');
    }

    public function testNameAndCsrfValidation(): void
    {
        $this->client->request('POST', '/settings/api/create', ['api_key_name' => ['name' => 'No CSRF']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->keyCount());
        $crawler = $this->client->request('GET', '/settings/api/create');
        $this->client->submit($crawler->selectButton('Создать ключ')->form(['api_key_name[name]' => '   ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field-helper.error');
        self::assertSame(0, $this->keyCount());
    }

    public function testRenameAndRevokeRequireCsrfAndPreserveExpiry(): void
    {
        [$key] = $this->key();
        $url = '/settings/api/'.$key->getId();
        $this->client->request('POST', $url.'/rename', ['api_key_name' => ['name' => 'changed']]);
        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->request('GET', $url.'/rename');
        $this->client->submit($crawler->selectButton('Сохранить')->form(['api_key_name[name]' => 'Renamed']));
        self::assertResponseRedirects('/settings/api', 303);
        $key = static::getContainer()->get(\App\Api\Repository\ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), (string) $this->company->getId());
        self::assertNotNull($key);
        $this->em()->refresh($key);
        self::assertSame('Renamed', $key->getName());
        self::assertSame('2026-12-12', $key->getExpiresAt()->format('Y-m-d'));
        $this->client->request('POST', $url.'/revoke');
        self::assertResponseStatusCodeSame(422);
        $key = static::getContainer()->get(\App\Api\Repository\ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), (string) $this->company->getId());
        self::assertNotNull($key);
        $this->em()->refresh($key);
        self::assertNull($key->getRevokedAt());
        $crawler = $this->client->request('GET', $url.'/revoke');
        $form = $crawler->selectButton('Отозвать ключ')->form();
        $values = $form->getPhpValues();
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/api', 303);
        $this->client->request('POST', $url.'/revoke', $values);
        self::assertResponseRedirects('/settings/api', 303);
        $key = static::getContainer()->get(\App\Api\Repository\ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), (string) $this->company->getId());
        self::assertNotNull($key);
        $this->em()->refresh($key);
        self::assertNotNull($key->getRevokedAt());
    }

    public function testPastedCheckUsesValidatorWithoutEchoAndRecordsSuccessfulUse(): void
    {
        [$key, $token] = $this->key();
        $this->check($token, 200);
        self::assertSelectorTextContains('.alert', 'Подключение работает');
        $key = static::getContainer()->get(\App\Api\Repository\ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), (string) $this->company->getId());
        self::assertNotNull($key);
        $this->em()->refresh($key);
        self::assertNotNull($key->getLastUsedAt());
        $this->clock->modify('+90 days');
        $this->check($token, 401);
        self::assertSelectorTextContains('.alert', 'недействителен');
        $this->client->request('POST', '/settings/api/check', ['api_key_check' => ['secret' => $token]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
    }

    public function testOwnerOfOtherCompanyCannotReadOrMutateActiveMembershipCompany(): void
    {
        [$key] = $this->key();
        $otherOwner = UserBuilder::aUser()->withIndex(9861)->build();
        $other = CompanyBuilder::aCompany()->withIndex(9861)->withOwner($otherOwner)->build();
        $this->em()->persist($otherOwner);
        $this->em()->persist($other);
        $role = new \App\Company\Entity\CompanyRole(\Ramsey\Uuid\Uuid::uuid7()->toString(), 'API test admin', ['admin' => 'write'], $this->company);
        $this->em()->persist($role);
        $this->em()->persist(CompanyMemberBuilder::aMember()->withCompany($this->company)->withUser($otherOwner)->withAccessRole($role)->build());
        $this->em()->flush();
        $this->client->loginUser($otherOwner);
        $this->setClientSessionValue($this->client, 'active_company_id', $this->company->getId());
        foreach (['', '/create', '/check', '/'.$key->getId().'/rename', '/'.$key->getId().'/revoke'] as $suffix) {
            $this->client->request('GET', '/settings/api'.$suffix);
            self::assertResponseStatusCodeSame(403);
            if ('' !== $suffix) {
                $this->client->request('POST', '/settings/api'.$suffix);
                self::assertResponseStatusCodeSame(403);
            }
        }
        $key = static::getContainer()->get(\App\Api\Repository\ApiKeyRepository::class)->findOneByIdAndCompany($key->getId(), (string) $this->company->getId());
        self::assertNotNull($key);
        $this->em()->refresh($key);
        self::assertSame('Existing', $key->getName());
        self::assertNull($key->getRevokedAt());
        self::assertSame(1, $this->keyCount());
    }

    public function testOtherCompanyKeyIsAbsentAndCannotBeManagedOrChecked(): void
    {
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $other = CompanyBuilder::aCompany()->withIndex(9862)->withOwner($owner)->build();
        $this->em()->persist($other);
        [$key, $token] = $this->key($other);
        $this->client->request('GET', '/settings/api');
        self::assertSelectorExists('.empty');
        foreach (['rename', 'revoke'] as $action) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, '/settings/api/'.$key->getId().'/'.$action);
                self::assertResponseStatusCodeSame(404);
            }
        }
        $this->check($token, 403);
    }

    public function testListPagesAndStatusesNeverExposeCredentials(): void
    {
        [$active, $token] = $this->key();
        [$expired] = $this->key();
        [$revoked] = $this->key();
        $this->em()->getConnection()->executeStatement('UPDATE api_keys SET expires_at = ? WHERE id = ?', ['2026-09-13 12:00:00', $expired->getId()]);
        $this->em()->getConnection()->executeStatement('UPDATE api_keys SET revoked_at = ? WHERE id = ?', ['2026-09-13 11:00:00', $revoked->getId()]);
        $this->client->request('GET', '/settings/api?limit=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.t-table tbody tr');
        self::assertSelectorExists('a[href="/settings/api?page=2&limit=2"]');
        $this->client->request('GET', '/settings/api?page=2&limit=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.t-table tbody tr');
        $this->client->request('GET', '/settings/api');
        self::assertSelectorTextContains('.status--success', 'Действует');
        self::assertSelectorTextContains('.status--warning', 'Истёк');
        self::assertSelectorTextContains('.status--danger', 'Отозван');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($token, $html);
        self::assertStringNotContainsString($active->getPublicIdentifier(), $html);
        $hash = $this->em()->getConnection()->fetchOne('SELECT secret_hash FROM api_keys WHERE id = ?', [$active->getId()]);
        self::assertIsString($hash);
        self::assertStringNotContainsString($hash, $html);
    }

    public function testPastedCheckRateLimitIsReadableAndDoesNotEcho(): void
    {
        [$key, $token] = $this->key();
        $factory = static::getContainer()->get('limiter.external_api_key');
        $factory->create($key->getId())->consume(60);
        $this->check($token, 429);
        self::assertSelectorTextContains('.alert', 'Слишком много');
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    private function check(string $token, int $status): void
    {
        $crawler = $this->client->request('GET', '/settings/api/check');
        $this->client->submit($crawler->selectButton('Проверить ключ')->form(['api_key_check[secret]' => $token]));
        self::assertResponseStatusCodeSame($status);
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('input[type="password"]');
        self::assertSame('', $this->client->getCrawler()->filter('input[type="password"]')->attr('value') ?? '');
    }

    /** @return array{ApiKey, string} */
    private function key(?Company $company = null): array
    {
        $company ??= $this->company;
        $secret = ApiKeySecret::generate();
        $owner = $company->getUser();
        self::assertNotNull($owner);
        $key = new ApiKey((string) $company->getId(), 'Existing', $secret['publicIdentifier'], ApiKeySecret::hash($secret['secret']), (string) $owner->getId(), $this->clock->now());
        $this->em()->persist($key);
        $this->em()->flush();

        return [$key, ApiKeySecret::format($secret['publicIdentifier'], $secret['secret'])];
    }

    private function keyCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM api_keys WHERE company_id = ?', [$this->company->getId()]);
    }
}
