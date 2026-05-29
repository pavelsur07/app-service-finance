<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CashflowJsonExportControllerTest extends WebTestCaseBase
{
    private const EXPORT_URL = '/finance/reports/cashflow/export.json';

    public function testGuestIsRedirectedOrForbidden(): void
    {
        $client = static::createClient();

        $client->request('GET', self::EXPORT_URL);

        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [302, 403]);
        if (302 === $statusCode) {
            self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        }
    }

    public function testAuthorizedUserGetsJsonAttachment(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a1');
        $this->seedCashflowData($company, '100.00', '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-30&group=month');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="cashflow-report-.*\.json"$/',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testQueryParametersAreReflectedInPayloadAndAffectPeriods(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a2');
        $this->seedCashflowData($company, '150.00', '2026-04-02');
        $this->seedCashflowData($company, '250.00', '2026-04-09');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-14&group=week');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame('week', $payload['group']);
        self::assertSame('week', $payload['filters']['group']);
        self::assertSame('2026-04-01', $payload['date_from']);
        self::assertSame('2026-04-01', $payload['filters']['date_from']);
        self::assertSame('2026-04-14', $payload['date_to']);
        self::assertSame('2026-04-14', $payload['filters']['date_to']);
        self::assertCount(2, $payload['periods']);
        self::assertSame('2026-04-01', $payload['periods'][0]['start']);
        self::assertSame('2026-04-07', $payload['periods'][0]['end']);
        self::assertSame('2026-04-08', $payload['periods'][1]['start']);
        self::assertSame('2026-04-14', $payload['periods'][1]['end']);
    }

    public function testPayloadContainsRequiredTopLevelKeys(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$user, $company] = $this->seedCompanyContext('a3');
        $this->seedCashflowData($company, '100.00', '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-30&group=month');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        foreach ([
            'company',
            'group',
            'date_from',
            'date_to',
            'periods',
            'openings',
            'closings',
            'tree',
            'categoryTree',
            'categoryTotals',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
    }

    public function testExportIsScopedToCurrentUsersActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$userA, $companyA] = $this->seedCompanyContext('a4');
        [, $companyB] = $this->seedCompanyContext('b4');
        $categoryA = $this->seedCashflowData($companyA, '100.00', '2026-04-15');
        $categoryB = $this->seedCashflowData($companyB, '900.00', '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $userA, $companyA);

        $client->request('GET', self::EXPORT_URL.'?from=2026-04-01&to=2026-04-30&group=month');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame($companyA->getId(), $payload['company']);
        self::assertArrayHasKey($categoryA->getId(), $payload['categoryTotals']);
        self::assertArrayNotHasKey($categoryB->getId(), $payload['categoryTotals']);
        self::assertEquals([100.0], $payload['categoryTotals'][$categoryA->getId()]['totals']['RUB']);
        self::assertEquals([100.0], $payload['closings']['RUB']);
    }

    /** @return array{0: User, 1: Company} */
    private function seedCompanyContext(string $suffix): array
    {
        $user = UserBuilder::aUser()
            ->withId(sprintf('22222222-2222-2222-2222-%s', str_pad($suffix, 12, '0', \STR_PAD_LEFT)))
            ->withEmail(sprintf('cashflow-export-%s@example.test', $suffix))
            ->asCompanyOwner()
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(sprintf('11111111-1111-1111-1111-%s', str_pad($suffix, 12, '0', \STR_PAD_LEFT)))
            ->withName(sprintf('Cashflow Export Company %s', $suffix))
            ->withOwner($user)
            ->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);

        return [$user, $company];
    }

    private function seedCashflowData(Company $company, string $amount, string $occurredAt): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Operating inflow');

        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main account '.substr($company->getId(), -2),
            'RUB',
        );
        $account->setOpeningBalance('0.00');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable($occurredAt),
        );
        $transaction->setCashflowCategory($category);

        $em = $this->em();
        $em->persist($category);
        $em->persist($account);
        $em->persist($transaction);

        return $category;
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }

    /** @return array<string, mixed> */
    private function decodeJson(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
