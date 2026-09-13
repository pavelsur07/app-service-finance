<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Api\Domain\ApiKeySecret;
use App\Api\Entity\ApiKey;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class ExternalPermissionsTest extends WebTestCaseBase
{
    private const IP = '192.0.2.93';
    private KernelBrowser $client;
    private Company $company;
    private ApiKey $key;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $clock = new MockClock('2026-09-13 12:00:00 UTC');
        static::getContainer()->set(ClockInterface::class, $clock);
        $owner = UserBuilder::aUser()->withIndex(9810)->build();
        $this->company = CompanyBuilder::aCompany()->withIndex(9810)->withOwner($owner)->build();
        $parts = ApiKeySecret::generate();
        $this->key = new ApiKey((string) $this->company->getId(), 'Permissions test', $parts['publicIdentifier'], ApiKeySecret::hash($parts['secret']), (string) $owner->getId(), $clock->now());
        $this->token = ApiKeySecret::format($parts['publicIdentifier'], $parts['secret']);
        $this->em()->persist($owner);
        $this->em()->persist($this->company);
        $this->em()->persist($this->key);
        $this->em()->flush();
        $limiter = static::getContainer()->get('limiter.external_api_failures');
        $limiter->create(self::IP)->reset();
    }

    public function testPreparedScopesRemainDisconnectedEvenWithOwnerEnabledResources(): void
    {
        $this->key->setPermissions(['accounts.read', 'cash_transactions.create'], ['accounts', 'cash_transactions']);
        $this->em()->flush();
        $response = $this->request('/auth/check');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(['accounts.read', 'cash_transactions.create'], $data['prepared_scopes']);
        self::assertSame([], $data['effective_scopes']);
        $this->assertDenied($this->request('/__test/permissions/accounts.read'));
        $this->assertDenied($this->request('/__test/permissions/cash_transactions.create'));
    }

    public function testMissingUnknownAndEveryUnpreparedScopeFailClosedOverHttp(): void
    {
        foreach ([
            'missing', 'unknown', 'accounts.read', 'counterparties.read', 'cash_categories.read', 'pl_categories.read',
            'projects.read', 'responsibility_centers.read',
            'cash_transactions.read', 'cash_transactions.create', 'cash_transactions.update', 'cash_transactions.soft_delete',
            'pl_operations.read', 'pl_operations.create', 'pl_operations.update', 'pl_operations.soft_delete',
            'cashflow_reports.read', 'pl_reports.read',
        ] as $scope) {
            $this->assertDenied($this->request('/__test/permissions/'.$scope));
        }
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT last_used_at FROM api_keys WHERE id = ?', [$this->key->getId()]));
    }

    public function testPermissionChangesAreReloadedOnTheNextAuthenticatedRequest(): void
    {
        $this->key->setPermissions(['cash_transactions.read'], []);
        $this->em()->flush();
        self::assertSame(['cash_transactions.read'], $this->json($this->request('/auth/check'))['prepared_scopes']);
        // Simulate a separate committed writer's fields without reusing a managed entity snapshot.
        $this->em()->getConnection()->executeStatement(
            'UPDATE api_keys SET selected_scopes = :scopes, enabled_resources = :resources, version = version + 1 WHERE id = :id',
            ['scopes' => '["cash_transactions.update"]', 'resources' => '["cash_transactions"]', 'id' => $this->key->getId()],
        );
        $response = $this->request('/auth/check');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(['cash_transactions.update'], $data['prepared_scopes']);
        self::assertSame([], $data['effective_scopes']);
        $this->assertDenied($this->request('/__test/permissions/cash_transactions.read'));
        $this->assertDenied($this->request('/__test/permissions/cash_transactions.update'));
    }

    private function request(string $path): Response
    {
        $this->client->request('GET', '/api/external/v1'.$path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_X_COMPANY_ID' => (string) $this->company->getPublicId(),
            'REMOTE_ADDR' => self::IP,
        ]);

        return $this->client->getResponse();
    }

    private function assertDenied(Response $response): void
    {
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame(403, $this->json($response)['status']);
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
