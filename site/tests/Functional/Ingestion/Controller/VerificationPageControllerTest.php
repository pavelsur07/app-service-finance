<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class VerificationPageControllerTest extends WebTestCaseBase
{
    public function testVerificationPagesRenderForAuthenticatedCompanyUser(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withIndex(9200)->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119200')
            ->withOwner($owner)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/ingestion/verification');
        self::assertResponseRedirects('/ingestion/verification/issues');

        $client->request('GET', '/ingestion/verification/coverage');
        self::assertResponseRedirects('/ingestion/coverage');

        foreach ($this->pages() as [$url, $mountId, $entryKey, $activeTabLabel]) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful();
            self::assertSame(1, $crawler->filter(sprintf('#%s', $mountId))->count());
            self::assertSame(
                $entryKey,
                $crawler->filter(sprintf('#%s', $mountId))->attr('data-vite-entry'),
            );
            $this->assertIngestionMenu($crawler);

            self::assertSame(3, $crawler->filter('.nav-tabs .nav-link')->count());
            self::assertSame(
                $url,
                $crawler->filter('.nav-tabs .nav-link.active')->attr('href'),
            );
            self::assertSame(
                $activeTabLabel,
                trim($crawler->filter('.nav-tabs .nav-link.active')->text()),
            );
            self::assertStringNotContainsString(
                'Покрытие',
                trim($crawler->filter('.nav-tabs')->text()),
            );
        }

        $crawler = $client->request('GET', '/ingestion/coverage');

        self::assertResponseIsSuccessful();
        $this->assertIngestionMenu($crawler);
        self::assertSame(1, $crawler->filter('#ingestion-verification-coverage-root')->count());
        self::assertSame(
            'ingestion_verification_coverage_page',
            $crawler->filter('#ingestion-verification-coverage-root')->attr('data-vite-entry'),
        );
        self::assertSame(0, $crawler->filter('.nav-tabs .nav-link')->count());
    }

    public function testVerificationPagesRequireAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ingestion/coverage');

        $response = $client->getResponse();
        $statusCode = $response->getStatusCode();

        self::assertTrue(
            $statusCode === 302 || $statusCode === 401 || $statusCode === 403,
            sprintf('Expected auth response, got HTTP %d.', $statusCode),
        );

        if ($statusCode === 302) {
            self::assertStringContainsString('/login', (string) $response->headers->get('Location'));
        }
    }

    /**
     * @return iterable<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function pages(): iterable
    {
        yield [
            '/ingestion/verification/issues',
            'ingestion-verification-issues-root',
            'ingestion_verification_issues_page',
            'Проблемы',
        ];
        yield [
            '/ingestion/verification/reconciliation',
            'ingestion-verification-reconciliation-root',
            'ingestion_verification_reconciliation_page',
            'Сверка сумм',
        ];
        yield [
            '/ingestion/verification/financial-summary',
            'ingestion-verification-financial-summary-root',
            'ingestion_verification_financial_summary_page',
            'Финансовая сводка',
        ];
    }

    private function assertIngestionMenu(Crawler $crawler): void
    {
        $items = $crawler->filter('aside a[href="/ingestion/verification"], aside a[href="/ingestion/coverage"]');

        self::assertSame(2, $items->count());
        self::assertSame('Финансы', trim($items->eq(0)->text()));
        self::assertSame('Покрытие данных', trim($items->eq(1)->text()));
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
