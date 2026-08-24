<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class WbFinanceSyncStatusControllerTest extends WebTestCaseBase
{
    public function testReturnsFailedDayWithErrorMessage(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $businessDate = (new \DateTimeImmutable('today'))->modify('-2 days');
        $this->seedAuthFailedStatus((string) $company->getId(), $businessDate);

        $client->request('GET', '/api/marketplace/wb-finance/sync-statuses');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(1, $payload['total']);
        $item = $payload['items'][0];
        self::assertSame($businessDate->format('Y-m-d'), $item['business_date']);
        self::assertSame('auth_failed', $item['status']);
        self::assertSame('Ошибка авторизации', $item['status_label']);
        self::assertSame('Invalid API token', $item['error_message']);
        self::assertSame(401, $item['http_status']);
    }

    public function testRejectsLimitOverMaximumWith422(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', '/api/marketplace/wb-finance/sync-statuses?limit=500');

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('invalid_pagination_limit', $payload['error']['code']);
    }

    public function testDoesNotLeakStatusesOfOtherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $otherUser = UserBuilder::aUser()
            ->withId('33333333-3333-4333-8333-333333333333')
            ->withEmail('other-wb-sync@test.local')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId('44444444-4444-4444-4444-444444444444')
            ->withOwner($otherUser)
            ->build();
        $em = $this->em();
        $em->persist($otherUser);
        $em->persist($otherCompany);
        $em->flush();

        $this->seedAuthFailedStatus((string) $otherCompany->getId(), (new \DateTimeImmutable('today'))->modify('-1 day'));

        $client->request('GET', '/api/marketplace/wb-finance/sync-statuses');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(0, $payload['total']);
        self::assertSame([], $payload['items']);
    }

    public function testDoesNotLeakOtherMarketplaceOrReportTypeStatuses(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $businessDate = (new \DateTimeImmutable('today'))->modify('-1 day');
        $this->seedAuthFailedStatus((string) $company->getId(), $businessDate);
        $this->seedStatus((string) $company->getId(), $businessDate, MarketplaceType::OZON, 'sales_report');
        $this->seedStatus((string) $company->getId(), $businessDate, MarketplaceType::WILDBERRIES, 'ads_report');

        $client->request('GET', '/api/marketplace/wb-finance/sync-statuses');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(1, $payload['total']);
        self::assertSame('wildberries', $payload['items'][0]['marketplace']);
        self::assertSame('sales_report', $payload['items'][0]['report_type']);
    }

    private function seedAuthFailedStatus(string $companyId, \DateTimeImmutable $businessDate): void
    {
        $status = $this->seedStatus($companyId, $businessDate, MarketplaceType::WILDBERRIES, 'sales_report');
        $status->markAuthFailed('App\Marketplace\Exception\MarketplaceAuthException', 'Invalid API token', 401, null);

        $this->em()->flush();
    }

    private function seedStatus(
        string $companyId,
        \DateTimeImmutable $businessDate,
        MarketplaceType $marketplace,
        string $reportType,
    ): MarketplaceFinancialReportSyncStatus {
        $status = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $companyId,
            Uuid::uuid7()->toString(),
            $marketplace,
            $reportType,
            sprintf('%s::%s', $marketplace->value, $reportType),
            $businessDate,
        );

        $em = $this->em();
        $em->persist($status);
        $em->flush();

        return $status;
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        // active_company_id в сессии не нужен: ActiveCompanyService сам
        // выбирает первую компанию пользователя при пустой сессии.
        $client->loginUser($user);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedBaseData(): array
    {
        $user = UserBuilder::aUser()->withEmail('wb-sync-status@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }
}
