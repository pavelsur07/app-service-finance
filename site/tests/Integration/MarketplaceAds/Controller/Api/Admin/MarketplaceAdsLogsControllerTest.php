<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api\Admin;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class MarketplaceAdsLogsControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-a00000000001';
    private const ADMIN_ID = '22222222-2222-2222-2222-a00000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-a00000000002';

    private const URL = '/api/marketplace-ads/admin/logs';

    protected function tearDown(): void
    {
        $this->cleanupLogFiles();
        parent::tearDown();
    }

    public function testReturns200AndTailForSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('ads-logs-admin@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($admin)
            ->build();

        $em->persist($admin);
        $em->persist($company);
        $em->flush();

        $this->writeLogFile('marketplace_ads-2026-04-19.log', [
            'line-1',
            'line-2',
            'line-3',
        ]);

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('charset=utf-8', (string) $response->headers->get('Content-Type'));

        $body = $response->getContent();
        self::assertStringContainsString('line-1', $body);
        self::assertStringContainsString('line-2', $body);
        self::assertStringContainsString('line-3', $body);
    }

    public function testReturns403ForCompanyOwnerWithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-logs-403-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRedirectsOrDeniesWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', self::URL);

        $response = $client->getResponse();
        self::assertTrue(
            $response->isRedirect() || 401 === $response->getStatusCode() || 403 === $response->getStatusCode(),
            'Unauthenticated access must be redirected or denied, got ' . $response->getStatusCode(),
        );
    }

    public function testReturns200WithMessageWhenNoLogFile(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $this->cleanupLogFiles();

        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('ads-logs-nofile@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($admin)
            ->build();

        $em->persist($admin);
        $em->persist($company);
        $em->flush();

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSame('No log file yet', $client->getResponse()->getContent());
    }

    public function testLinesParameterLimitsOutput(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('ads-logs-lines@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($admin)
            ->build();

        $em->persist($admin);
        $em->persist($company);
        $em->flush();

        $rows = [];
        for ($i = 1; $i <= 50; ++$i) {
            $rows[] = sprintf('row-%03d', $i);
        }
        $this->writeLogFile('marketplace_ads-2026-04-19.log', $rows);

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', self::URL . '?lines=10');

        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->getContent();
        $returned = $body === '' ? [] : explode("\n", $body);
        self::assertLessThanOrEqual(10, count($returned));
        self::assertStringContainsString('row-050', $body);
        self::assertStringNotContainsString('row-001', $body);
    }

    public function testSearchFiltersLines(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('ads-logs-search@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($admin)
            ->build();

        $em->persist($admin);
        $em->persist($company);
        $em->flush();

        $this->writeLogFile('marketplace_ads-2026-04-19.log', [
            'ozon ad loaded',
            'wildberries ignored',
            'ozon error 500',
            'random stuff',
        ]);

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', self::URL . '?search=ozon');

        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->getContent();
        self::assertStringContainsString('ozon ad loaded', $body);
        self::assertStringContainsString('ozon error 500', $body);
        self::assertStringNotContainsString('wildberries', $body);
        self::assertStringNotContainsString('random stuff', $body);
    }

    public function testPicksLatestFileByMtime(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('ads-logs-latest@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($admin)
            ->build();

        $em->persist($admin);
        $em->persist($company);
        $em->flush();

        $oldPath = $this->writeLogFile('marketplace_ads-2026-04-18.log', ['stale-line']);
        $newPath = $this->writeLogFile('marketplace_ads-2026-04-19.log', ['fresh-line']);
        touch($oldPath, time() - 3600);
        touch($newPath, time());

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->getContent();
        self::assertStringContainsString('fresh-line', $body);
        self::assertStringNotContainsString('stale-line', $body);
    }

    private function logsDir(): string
    {
        return static::getContainer()->getParameter('kernel.logs_dir');
    }

    /**
     * @param list<string> $lines
     */
    private function writeLogFile(string $name, array $lines): string
    {
        $dir = $this->logsDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function cleanupLogFiles(): void
    {
        $dir = $this->logsDir();
        if (!is_dir($dir)) {
            return;
        }
        $matches = glob($dir . '/marketplace_ads*.log');
        if (false === $matches) {
            return;
        }
        foreach ($matches as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function loginAs($client, $user, string $companyId): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }
}
