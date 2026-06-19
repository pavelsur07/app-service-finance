<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\PLMonthlySnapshot;
use App\Finance\Enum\PLFlow;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Marketplace\Entity\OzonTransactionTotalsCheck;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class VerificationApiControllerTest extends WebTestCaseBase
{
    public function testVerificationEndpointsReturnExpectedPayloads(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company] = $this->persistVerificationFixture();
        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/api/ingestion/verification/coverage?from=2026-06-01&to=2026-06-30&shop_ref=shop-1');
        self::assertResponseIsSuccessful();
        $coverage = $this->json($client);
        self::assertSame('shop-1', $coverage['cells'][0]['shop_ref']);
        self::assertSame(1, $coverage['cells'][0]['raw_count']);
        self::assertSame(2, $coverage['cells'][0]['tx_count']);
        self::assertSame(1, $coverage['cells'][0]['issue_count']);
        self::assertSame('shop-1', $coverage['shops'][0]['shop_ref']);

        $client->request('GET', '/api/ingestion/verification/reconciliation?shop_ref=shop-1&year=2026&month=6');
        self::assertResponseIsSuccessful();
        $reconciliation = $this->json($client);
        self::assertSame(800, $reconciliation['summary']['canon_total_minor']);
        self::assertSame(750, $reconciliation['summary']['ozon_control_total_minor']);
        self::assertSame(50, $reconciliation['summary']['canon_vs_ozon_delta_minor']);
        self::assertSame('sale', $reconciliation['by_type'][1]['type']);

        $client->request('GET', '/api/ingestion/verification/issues?shop_ref=shop-1&page=1&limit=50');
        self::assertResponseIsSuccessful();
        $issues = $this->json($client);
        self::assertSame(1, $issues['meta']['total']);
        self::assertSame('sum_mismatch', $issues['items'][0]['kind']);
        self::assertArrayNotHasKey('details', $issues['items'][0]);
        self::assertArrayNotHasKey('raw_record_id', $issues['items'][0]);
        self::assertArrayNotHasKey('operation_group_id', $issues['items'][0]);

        $client->request('GET', '/api/ingestion/verification/financial-summary?year_from=2026&month_from=6&year_to=2026&month_to=6');
        self::assertResponseIsSuccessful();
        $summary = $this->json($client);
        self::assertSame(12345, $summary['by_month'][0]['income_minor']);
        self::assertSame(2345, $summary['by_month'][0]['expense_minor']);
        self::assertSame(10000, $summary['by_month'][0]['net_minor']);
        self::assertSame('income', $summary['by_category'][1]['flow']);
    }

    public function testInvalidPeriodUsesUnifiedErrorFormat(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()->withIndex(9101)->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119101')
            ->withOwner($owner)
            ->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $this->loginWithActiveCompany($client, $owner, $company);
        $client->request('GET', '/api/ingestion/verification/coverage?from=2026-07-01&to=2026-06-01');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([
            'error' => [
                'code' => 'invalid_period_range',
                'message' => 'Некорректный диапазон периода',
            ],
        ], $this->json($client));
    }

    public function testVerificationEndpointsDoNotLeakOtherCompanyData(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$ownerA, $companyA] = $this->persistTenantLeakFixture();
        $this->loginWithActiveCompany($client, $ownerA, $companyA);

        $client->request('GET', '/api/ingestion/verification/coverage?from=2026-06-01&to=2026-06-30');
        self::assertResponseIsSuccessful();
        $coverage = $this->json($client);
        self::assertSame(['shop-a'], array_column($coverage['cells'], 'shop_ref'));
        self::assertSame(['shop-a'], array_column($coverage['shops'], 'shop_ref'));

        $client->request('GET', '/api/ingestion/verification/reconciliation?shop_ref=shop-a&year=2026&month=6');
        self::assertResponseIsSuccessful();
        $reconciliation = $this->json($client);
        self::assertSame(1000, $reconciliation['summary']['canon_total_minor']);
        self::assertSame(900, $reconciliation['summary']['ozon_control_total_minor']);

        $client->request('GET', '/api/ingestion/verification/issues?page=1&limit=50');
        self::assertResponseIsSuccessful();
        $issues = $this->json($client);
        self::assertSame(1, $issues['meta']['total']);
        self::assertSame('sum_mismatch', $issues['items'][0]['kind']);

        $client->request('GET', '/api/ingestion/verification/financial-summary?year_from=2026&month_from=6&year_to=2026&month_to=6');
        self::assertResponseIsSuccessful();
        $summary = $this->json($client);
        self::assertSame(1000, $summary['by_month'][0]['income_minor']);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function persistVerificationFixture(): array
    {
        $owner = UserBuilder::aUser()->withIndex(9100)->build();
        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119100')
            ->withOwner($owner)
            ->build();
        $companyId = $company->getId();
        $raw = $this->rawRecord($companyId, 'shop-1', 'ozon_seller_daily_report', 'raw-api-1');

        $check = new OzonTransactionTotalsCheck(
            companyId: $companyId,
            rawDocumentId: Uuid::uuid7()->toString(),
            periodFrom: new \DateTimeImmutable('2026-06-01'),
            periodTo: new \DateTimeImmutable('2026-06-30'),
        );
        $check->markOk([], ['total_minor' => 750], []);

        $incomeCategory = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339100')
            ->forCompany($company)
            ->withName('Продажи')
            ->withFlow(PLFlow::INCOME)
            ->build();
        $expenseCategory = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339101')
            ->forCompany($company)
            ->withName('Комиссии')
            ->withFlow(PLFlow::EXPENSE)
            ->build();
        $incomeSnapshot = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $company, '2026-06', $incomeCategory);
        $incomeSnapshot->setAmountIncome('123.45');
        $incomeSnapshot->setRebuiltAt(new \DateTimeImmutable('2026-06-20 10:00:00+00:00'));
        $expenseSnapshot = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $company, '2026-06', $expenseCategory);
        $expenseSnapshot->setAmountExpense('23.45');
        $expenseSnapshot->setRebuiltAt(new \DateTimeImmutable('2026-06-20 10:00:00+00:00'));

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($raw);
        $em->persist($this->transaction($companyId, $raw->getId(), 'api-sale-1', 1000, TransactionType::SALE));
        $em->persist($this->transaction($companyId, $raw->getId(), 'api-commission-1', -200, TransactionType::COMMISSION));
        $em->persist(new NormalizationIssue(
            $companyId,
            $raw->getId(),
            Uuid::uuid7()->toString(),
            NormalizationIssueKind::SUM_MISMATCH,
            ['raw' => 'hidden'],
        ));
        $em->persist($check);
        $em->persist($incomeCategory);
        $em->persist($expenseCategory);
        $em->persist($incomeSnapshot);
        $em->persist($expenseSnapshot);
        $em->flush();

        return [$owner, $company];
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function persistTenantLeakFixture(): array
    {
        $ownerA = UserBuilder::aUser()->withIndex(9200)->build();
        $ownerB = UserBuilder::aUser()->withIndex(9201)->build();
        $companyA = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119200')
            ->withOwner($ownerA)
            ->build();
        $companyB = CompanyBuilder::aCompany()
            ->withId('11111111-1111-4111-8111-111111119201')
            ->withOwner($ownerB)
            ->build();

        $rawA = $this->rawRecord($companyA->getId(), 'shop-a', 'ozon_seller_daily_report', 'tenant-raw-a');
        $rawB = $this->rawRecord($companyB->getId(), 'shop-b', 'ozon_seller_daily_report', 'tenant-raw-b');

        $checkA = new OzonTransactionTotalsCheck(
            companyId: $companyA->getId(),
            rawDocumentId: Uuid::uuid7()->toString(),
            periodFrom: new \DateTimeImmutable('2026-06-01'),
            periodTo: new \DateTimeImmutable('2026-06-30'),
        );
        $checkA->markOk([], ['total_minor' => 900], []);
        $checkB = new OzonTransactionTotalsCheck(
            companyId: $companyB->getId(),
            rawDocumentId: Uuid::uuid7()->toString(),
            periodFrom: new \DateTimeImmutable('2026-06-01'),
            periodTo: new \DateTimeImmutable('2026-06-30'),
        );
        $checkB->markOk([], ['total_minor' => 99999], []);

        $categoryA = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339200')
            ->forCompany($companyA)
            ->withName('Company A Sales')
            ->withFlow(PLFlow::INCOME)
            ->build();
        $categoryB = PLCategoryBuilder::aPLCategory()
            ->withId('33333333-3333-4333-8333-333333339201')
            ->forCompany($companyB)
            ->withName('Company B Sales')
            ->withFlow(PLFlow::INCOME)
            ->build();
        $snapshotA = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $companyA, '2026-06', $categoryA);
        $snapshotA->setAmountIncome('10.00');
        $snapshotA->setRebuiltAt(new \DateTimeImmutable('2026-06-20 10:00:00+00:00'));
        $snapshotB = new PLMonthlySnapshot(Uuid::uuid7()->toString(), $companyB, '2026-06', $categoryB);
        $snapshotB->setAmountIncome('999.99');
        $snapshotB->setRebuiltAt(new \DateTimeImmutable('2026-06-20 10:00:00+00:00'));

        $em = $this->em();
        foreach ([$ownerA, $ownerB, $companyA, $companyB, $rawA, $rawB] as $entity) {
            $em->persist($entity);
        }
        $em->persist($this->transaction($companyA->getId(), $rawA->getId(), 'tenant-sale-a', 1000, TransactionType::SALE, 'shop-a'));
        $em->persist($this->transaction($companyB->getId(), $rawB->getId(), 'tenant-sale-b', 99999, TransactionType::SALE, 'shop-b'));
        $em->persist(new NormalizationIssue(
            $companyA->getId(),
            $rawA->getId(),
            null,
            NormalizationIssueKind::SUM_MISMATCH,
            [],
        ));
        $em->persist(new NormalizationIssue(
            $companyB->getId(),
            $rawB->getId(),
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        foreach ([$checkA, $checkB, $categoryA, $categoryB, $snapshotA, $snapshotB] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$ownerA, $companyA];
    }

    private function rawRecord(string $companyId, string $shopRef, string $resourceType, string $externalId): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: $shopRef,
            source: IngestSource::OZON,
            resourceType: $resourceType,
            externalId: $externalId,
            storagePath: sprintf('%s/%s.ndjson.gz', $companyId, $externalId),
            hash: hash('sha256', $companyId.$externalId),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            syncJobId: 'job-'.$externalId,
        );
    }

    private function transaction(
        string $companyId,
        string $rawRecordId,
        string $externalId,
        int $amountMinor,
        TransactionType $type,
        string $shopRef = 'shop-1',
    ): FinancialTransaction {
        return new FinancialTransaction(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: $shopRef,
            source: IngestSource::OZON,
            externalId: $externalId,
            externalUpdatedAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: $type,
            direction: $amountMinor >= 0 ? TransactionDirection::IN : TransactionDirection::OUT,
            money: Money::fromMinor($amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable('2026-06-15 10:00:00+00:00'),
            rawRecordId: $rawRecordId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
