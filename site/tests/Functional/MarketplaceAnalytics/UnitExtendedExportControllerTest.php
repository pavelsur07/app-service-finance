<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class UnitExtendedExportControllerTest extends WebTestCaseBase
{
    private const EXPORT_URL = '/api/marketplace-analytics/unit-extended/export';

    public function testHappyPathReturnsXlsxAttachment(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-export@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-111111111111')
            ->withOwner($owner)
            ->withName('Export Company')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginAsOwner($client, $owner, (string) $company->getId());

        $client->request('GET', self::EXPORT_URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
        ]);

        self::assertResponseStatusCodeSame(200);

        $response = $client->getResponse();
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('.xlsx', $disposition);

        $content = $client->getInternalResponse()->getContent();
        self::assertNotSame('', $content);
    }

    public function testReturnsErrorWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', self::EXPORT_URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
        ]);

        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [302, 401, 403], sprintf('Unexpected status for anonymous request: %d', $status));
    }

    public function testReturns400WhenPeriodFormatIsInvalid(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-export-bad-date@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId('33333333-3333-3333-3333-333333333333')
            ->withOwner($owner)
            ->withName('Export Company Bad Date')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginAsOwner($client, $owner, (string) $company->getId());

        $client->request('GET', self::EXPORT_URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026/04/01',
            'periodTo' => '2026-04-30',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testReturns400WhenPeriodFromMissing(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('owner-export-missing@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId('22222222-2222-2222-2222-222222222222')
            ->withOwner($owner)
            ->withName('Export Company Missing')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginAsOwner($client, $owner, (string) $company->getId());

        $client->request('GET', self::EXPORT_URL, [
            'marketplace' => 'ozon',
            'periodTo' => '2026-04-30',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    private function loginAsOwner(KernelBrowser $client, object $owner, string $companyId): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }
}
