<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\ApiKeySecret;
use App\Api\Entity\ApiKey;
use App\Company\Entity\Company;
use App\Company\Entity\ReportApiKey;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ExternalAuthenticationTest extends WebTestCaseBase
{
    private const IP = '192.0.2.91';
    private KernelBrowser $client;
    private MockClock $clock;
    private Company $company;
    private ApiKey $key;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-09-13 12:00:00 UTC');
        static::getContainer()->set(ClockInterface::class, $this->clock);
        $this->company = CompanyBuilder::aCompany()->withIndex(9801)->build();
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $this->em()->persist($owner);
        $this->em()->persist($this->company);
        [$this->key, $this->token] = $this->createKey();
        $this->em()->flush();
        $this->limiter('limiter.external_api_failures')->create(self::IP)->reset();
        $this->limiter('limiter.external_api_failures')->create('192.0.2.92')->reset();
        $this->limiter('limiter.external_api_key')->create($this->key->getId())->reset();
    }

    public function testValidKeyIsStatelessAndReturnsOnlyConnectionDetails(): void
    {
        self::assertNull($this->lastUsed());
        $response = $this->request();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'company_id' => $this->company->getPublicId(),
            'key_id' => $this->key->getId(),
            'expires_at' => '2026-12-12T12:00:00+00:00',
            'prepared_scopes' => [],
            'effective_scopes' => [],
        ], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $this->client->getCookieJar()->all());
        self::assertSame('2026-09-13 12:00:00', $this->lastUsed());
        $this->assertProblem($this->request(authorization: ''), 401);
        self::assertSame('2026-09-13 12:00:00', $this->lastUsed());
    }

    public function testMissingMalformedAndWrongSecretAreUnauthorized(): void
    {
        $wrongSecret = substr($this->token, 0, -1).('a' === substr($this->token, -1) ? 'b' : 'a');
        foreach (['', 'Basic '.$this->token, 'Bearer invalid', 'Bearer '.$wrongSecret, 'Bearer '.ApiKeySecret::format(str_repeat('f', 32), str_repeat('a', 64))] as $authorization) {
            $this->assertProblem($this->request(authorization: $authorization), 401);
        }
        self::assertNull($this->lastUsed());
    }

    public function testMissingOrMalformedCompanyHeaderIsUnprocessable(): void
    {
        foreach (['', '0', '-1', '100000.0', ' 100000', '2147483648', (string) $this->company->getId()] as $companyId) {
            $this->assertProblem($this->request(companyId: $companyId), 422);
        }
        self::assertNull($this->lastUsed());
    }

    public function testAnotherCompanyCannotBeSelectedWithValidKey(): void
    {
        $other = CompanyBuilder::aCompany()->withIndex(9802)->withOwner(UserBuilder::aUser()->withIndex(9802)->build())->build();
        $owner = $other->getUser();
        self::assertNotNull($owner);
        $this->em()->persist($owner);
        $this->em()->persist($other);
        $this->em()->flush();
        $this->assertProblem($this->request(companyId: (string) $other->getPublicId()), 403);
        self::assertNull($this->lastUsed());
    }

    public function testExpirationBoundaryIsInclusiveAndDoesNotRecordFailedUse(): void
    {
        $this->clock->modify('2026-12-12 11:59:59 UTC');
        self::assertSame(200, $this->request()->getStatusCode());
        self::assertSame('2026-12-12 11:59:59', $this->lastUsed());
        $this->clock->modify('+1 second');
        $this->assertProblem($this->request(), 401);
        self::assertSame('2026-12-12 11:59:59', $this->lastUsed());
    }

    public function testRevocationAppliesToTheVeryNextRequest(): void
    {
        self::assertSame(200, $this->request()->getStatusCode());
        $this->em()->getConnection()->executeStatement('UPDATE api_keys SET revoked_at = ? WHERE id = ?', ['2026-09-13 12:00:01', $this->key->getId()]);
        $this->clock->modify('+2 seconds');
        $this->assertProblem($this->request(), 401);
        self::assertSame('2026-09-13 12:00:00', $this->lastUsed());
    }

    public function testQueryTokensNeverAuthenticate(): void
    {
        $this->assertProblem($this->request(authorization: '', query: ['token' => $this->token, 'access_token' => $this->token]), 401);
        self::assertNull($this->lastUsed());
    }

    public function testLegacyReportKeyCannotAuthenticateExternalApi(): void
    {
        $raw = 'rk_live_'.bin2hex(random_bytes(16));
        $legacy = new ReportApiKey($this->company, 'rk_live_', password_hash($raw, PASSWORD_ARGON2ID));
        $this->em()->persist($legacy);
        $this->em()->flush();
        $this->assertProblem($this->request(authorization: 'Bearer '.$raw), 401);
        self::assertNull($this->lastUsed());
    }

    public function testExternalKeyCannotAuthenticateLegacyReportRoute(): void
    {
        $this->client->request('GET', '/api/public/reports/cashflow.json', ['token' => $this->token], server: ['REMOTE_ADDR' => self::IP]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($this->lastUsed());
    }

    public function testSixtyRequestsPerKeyThenRateLimitedWhileAnotherKeyWorks(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            self::assertSame(200, $this->request()->getStatusCode(), 'Request '.($i + 1));
        }
        $this->clock->modify('+1 second');
        $response = $this->request();
        $this->assertProblem($response, 429);
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertSame('2026-09-13 12:00:00', $this->lastUsed());
        [, $otherToken] = $this->createKey();
        $this->em()->flush();
        self::assertSame(200, $this->request(authorization: 'Bearer '.$otherToken)->getStatusCode());
    }

    public function testMalformedCompanyRequestsConsumeTheAuthenticatedKeyBudget(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $companyId = 0 === $i % 2 ? '' : 'invalid';
            self::assertSame(422, $this->request(companyId: $companyId)->getStatusCode(), 'Request '.($i + 1));
        }

        $this->assertProblem($this->request(companyId: 'invalid'), 429);
        $this->assertProblem($this->request(companyId: ''), 429);
        $this->assertProblem($this->request(), 429);
        self::assertNull($this->lastUsed());
    }

    public function testTwentyFailedAttemptsPerIpThenRateLimitedWhileAnotherIpWorks(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(401, $this->request(authorization: 'Bearer invalid')->getStatusCode(), 'Failure '.($i + 1));
        }
        $this->assertProblem($this->request(authorization: 'Bearer invalid'), 429);
        self::assertNull($this->lastUsed());
        self::assertSame(200, $this->request(ip: '192.0.2.92')->getStatusCode());
    }

    /** @return array{ApiKey, string} */
    private function createKey(): array
    {
        $parts = ApiKeySecret::generate();
        $owner = $this->company->getUser();
        self::assertNotNull($owner);
        $key = new ApiKey((string) $this->company->getId(), 'Functional key', $parts['publicIdentifier'], ApiKeySecret::hash($parts['secret']), (string) $owner->getId(), $this->clock->now());
        $this->em()->persist($key);

        return [$key, ApiKeySecret::format($parts['publicIdentifier'], $parts['secret'])];
    }

    /** @param array<string, string> $query */
    private function request(?string $authorization = null, ?string $companyId = null, array $query = [], string $ip = self::IP): Response
    {
        $headers = ['REMOTE_ADDR' => $ip];
        if ('' !== $authorization) {
            $headers['HTTP_AUTHORIZATION'] = $authorization ?? 'Bearer '.$this->token;
        }
        if ('' !== $companyId) {
            $headers['HTTP_X_COMPANY_ID'] = $companyId ?? (string) $this->company->getPublicId();
        }
        $this->client->request('GET', '/api/external/v1/auth/check', $query, server: $headers);

        return $this->client->getResponse();
    }

    private function assertProblem(Response $response, int $status): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($status, $data['status']);
        self::assertSame('about:blank', $data['type']);
        self::assertArrayHasKey('title', $data);
        self::assertArrayHasKey('detail', $data);
        if (401 === $status) {
            self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        }
    }

    private function lastUsed(): ?string
    {
        $value = $this->em()->getConnection()->fetchOne('SELECT last_used_at FROM api_keys WHERE id = ?', [$this->key->getId()]);

        return null === $value ? null : (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function limiter(string $id): RateLimiterFactory
    {
        $factory = static::getContainer()->get($id);
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        return $factory;
    }
}
