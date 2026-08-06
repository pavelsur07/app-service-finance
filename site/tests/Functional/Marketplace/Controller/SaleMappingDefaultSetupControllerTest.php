<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Enum\PLCategoryType;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Writer\DefaultSaleMappingWriter;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SaleMappingDefaultSetupControllerTest extends WebTestCaseBase
{
    /** Ожидаемый результат автонастройки Ozon: источник суммы => [статья ОПиУ, инвертировать знак]. */
    private const EXPECTED_OZON = [
        'sale_gross' => ['REV_NOT_SPP', false],
        'sale_realization' => ['REV_SPP_SALES', false],
        'sale_cost_price' => ['COGS_PRODUCT_REV', false],
        'return_gross' => ['REV_RETURNS', true],
        'return_realization' => ['REV_SPP_RETURNS', true],
        'return_cost_price' => ['COGS_PRODUCT_RET', true],
    ];

    public function testPreviewIsReadOnlyAndProposesEveryRule(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $before = $this->countMappings();
        $payload = $this->post($client, '/marketplace/pl-mappings/default/preview', 'ozon');

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['ok']);
        self::assertFalse($payload['hasBlockingIssues']);
        self::assertSame($before, $this->countMappings());
        self::assertCount(6, $payload['items']);

        $byAmountSource = [];
        foreach ($payload['items'] as $item) {
            $byAmountSource[$item['amountSource']] = $item;
        }

        foreach (self::EXPECTED_OZON as $amountSource => [$plCode, $isNegative]) {
            self::assertArrayHasKey($amountSource, $byAmountSource);
            self::assertSame('will_create', $byAmountSource[$amountSource]['status']);
            self::assertSame($plCode, $byAmountSource[$amountSource]['plCode']);
            self::assertSame($isNegative, $byAmountSource[$amountSource]['isNegative']);
            self::assertNotNull($byAmountSource[$amountSource]['plCategoryId']);
        }
    }

    public function testApplyCreatesRulesWithCorrectSignAndIsIdempotent(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $first = $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');
        self::assertResponseIsSuccessful();
        self::assertTrue($first['ok']);
        self::assertSame(6, $first['summary']['created']);
        self::assertSame(6, $this->countMappings());

        // Возвраты обязаны быть отрицательными: иначе они прибавятся к выручке.
        foreach ($this->fetchMappings($company) as $row) {
            [$expectedPlCode, $expectedNegative] = self::EXPECTED_OZON[$row['amount_source']];

            self::assertSame($expectedPlCode, $row['pl_code'], $row['amount_source']);
            self::assertSame($expectedNegative, (bool) $row['is_negative'], $row['amount_source']);
            self::assertTrue((bool) $row['is_active'], $row['amount_source']);
        }

        $second = $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');
        self::assertTrue($second['ok']);
        self::assertSame(0, $second['summary']['created']);
        self::assertSame(6, $second['summary']['skipped']);
        self::assertSame(6, $this->countMappings());
    }

    public function testExistingRuleIsNeverOverwritten(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        // Ручное правило с «неправильным» знаком: автонастройка обязана его сохранить.
        $this->insertManualMapping($company, 'return', 'return_gross', 'REV_NOT_SPP', false);

        $apply = $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');
        self::assertTrue($apply['ok']);
        self::assertSame(5, $apply['summary']['created']);
        self::assertContains('return_gross', $apply['skippedAmountSources']);

        $manual = array_values(array_filter(
            $this->fetchMappings($company),
            static fn (array $row): bool => 'return_gross' === $row['amount_source'],
        ));

        self::assertCount(1, $manual);
        self::assertSame('REV_NOT_SPP', $manual[0]['pl_code']);
        self::assertFalse((bool) $manual[0]['is_negative']);
    }

    /**
     * Настроенное правило возврата с «плюсом» — это и есть дефект, найденный в
     * проде: возврат прибавляется к выручке. Автонастройка его не исправляет,
     * поэтому обязана хотя бы показать реальный знак и расхождение с эталоном,
     * а не нарисовать поверх ожидаемый «минус».
     */
    public function testExistingRuleWithWrongSignIsShownAsIsAndFlagged(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $this->insertManualMapping($company, 'return', 'return_gross', 'REV_RETURNS', false);

        $preview = $this->post($client, '/marketplace/pl-mappings/default/preview', 'ozon');
        $bySource = [];
        foreach ($preview['items'] as $item) {
            $bySource[$item['amountSource']] = $item;
        }

        $returnGross = $bySource['return_gross'];
        self::assertSame('skipped_existing', $returnGross['status']);
        self::assertFalse($returnGross['isNegative'], 'Показан должен быть реальный знак правила.');
        self::assertTrue($returnGross['expectedNegative']);
        self::assertTrue($returnGross['signMismatch']);

        // Правила без расхождения флаг не поднимают.
        self::assertFalse($bySource['return_cost_price']['signMismatch']);

        $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');

        $stored = array_values(array_filter(
            $this->fetchMappings($company),
            static fn (array $row): bool => 'return_gross' === $row['amount_source'],
        ));
        self::assertCount(1, $stored);
        self::assertFalse((bool) $stored[0]['is_negative'], 'Автонастройка не должна менять знак существующего правила.');
    }

    public function testDisabledRuleWithSameTargetIsReportedAndNotResurrected(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        // Правило отключено вручную, но занимает уникальный ключ.
        $this->insertManualMapping($company, 'sale', 'sale_gross', 'REV_NOT_SPP', false, false);

        $preview = $this->post($client, '/marketplace/pl-mappings/default/preview', 'ozon');
        $bySource = [];
        foreach ($preview['items'] as $item) {
            $bySource[$item['amountSource']] = $item;
        }
        self::assertSame('skipped_existing', $bySource['sale_gross']['status']);

        $apply = $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');
        self::assertTrue($apply['ok']);
        self::assertSame(5, $apply['summary']['created']);
        self::assertContains('sale_gross', $apply['skippedAmountSources']);

        $saleGross = array_values(array_filter(
            $this->fetchMappings($company),
            static fn (array $row): bool => 'sale_gross' === $row['amount_source'],
        ));

        self::assertCount(1, $saleGross);
        self::assertFalse((bool) $saleGross[0]['is_active'], 'Отключённое правило не должно оживать.');
    }

    public function testApplyTouchesOnlyActiveCompany(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        [, $otherCompany] = $this->seedBaseData(true, 'other-sale-mapping@test.local', 2);
        $this->loginWithActiveCompany($client, $user, $company);

        $apply = $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');
        self::assertSame(6, $apply['summary']['created']);

        self::assertCount(6, $this->fetchMappings($company));
        self::assertSame([], $this->fetchMappings($otherCompany), 'Чужая компания не должна быть затронута.');
        self::assertSame(6, $this->countMappings());
    }

    /**
     * Writer обязан сам отказать во втором активном правиле, не полагаясь на
     * индекс: иначе штатный повторный прогон автонастройки ловил бы
     * UniqueConstraintViolationException вместо тихого skip. Через preview этот
     * путь недостижим, поэтому writer вызывается напрямую.
     */
    public function testWriterRefusesSecondActiveRuleForSameAmountSource(): void
    {
        $this->resetDb();
        static::createClient();
        [, $company] = $this->seedBaseData();

        $this->insertManualMapping($company, 'sale', 'sale_gross', 'COGS_PRODUCT_REV', false);

        $writer = static::getContainer()->get(DefaultSaleMappingWriter::class);
        $plCategoryId = (string) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM pl_categories WHERE company_id = :companyId AND code = :code',
            ['companyId' => $company->getId(), 'code' => 'REV_NOT_SPP'],
        );

        $affected = $writer->createMapping(
            (string) $company->getId(),
            MarketplaceType::OZON,
            AmountSource::SALE_GROSS,
            $plCategoryId,
            'REV_NOT_SPP',
            false,
            null,
        );

        self::assertSame(0, $affected);
        self::assertCount(1, $this->fetchMappings($company));
    }

    /**
     * Проверка в writer'е спасает только от последовательного вызова: две
     * параллельные транзакции с разными pl_category_id прошли бы обе, потому что
     * uniq_sale_mapping различает их по категории. Инвариант закреплён частичным
     * индексом uniq_active_sale_mapping_source — здесь проверяется именно он.
     */
    public function testDatabaseRejectsSecondActiveRuleWithAnotherCategory(): void
    {
        $this->resetDb();
        static::createClient();
        [, $company] = $this->seedBaseData();

        $this->insertManualMapping($company, 'sale', 'sale_gross', 'REV_NOT_SPP', false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertManualMapping($company, 'sale', 'sale_gross', 'COGS_PRODUCT_REV', false);
    }

    public function testWriterIgnoresPlCategoryOfAnotherCompany(): void
    {
        $this->resetDb();
        static::createClient();
        [, $company] = $this->seedBaseData();
        [, $otherCompany] = $this->seedBaseData(true, 'writer-other@test.local', 2);

        $writer = static::getContainer()->get(DefaultSaleMappingWriter::class);
        $foreignPlCategoryId = (string) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM pl_categories WHERE company_id = :companyId AND code = :code',
            ['companyId' => $otherCompany->getId(), 'code' => 'REV_NOT_SPP'],
        );

        $affected = $writer->createMapping(
            (string) $company->getId(),
            MarketplaceType::OZON,
            AmountSource::SALE_GROSS,
            $foreignPlCategoryId,
            'REV_NOT_SPP',
            false,
            null,
        );

        self::assertSame(0, $affected);
        self::assertSame(0, $this->countMappings());
    }

    public function testApplyIsBlockedWhenPlTreeHasNoCodes(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData(false);
        $this->loginWithActiveCompany($client, $user, $company);

        $payload = $this->post($client, '/marketplace/pl-mappings/default/apply', 'ozon');

        self::assertFalse($payload['ok']);
        self::assertArrayHasKey('message', $payload);
        self::assertSame(0, $this->countMappings());
    }

    public function testUnknownMarketplaceAndInvalidCsrfAreRejected(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $payload = $this->post($client, '/marketplace/pl-mappings/default/preview', 'unknown');
        self::assertFalse($payload['ok']);

        $client->request('POST', '/marketplace/pl-mappings/default/apply', [
            'marketplace' => 'ozon',
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function post(KernelBrowser $client, string $uri, string $marketplace): array
    {
        $client->request('POST', $uri, [
            'marketplace' => $marketplace,
            '_token' => $this->csrfToken($client, 'marketplace_default_sale_mapping'),
        ]);

        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function countMappings(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_sale_mappings');
    }

    /** @return list<array{amount_source: string, pl_code: string, is_negative: bool, is_active: bool}> */
    private function fetchMappings(Company $company): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT m.amount_source, c.code AS pl_code, m.is_negative, m.is_active
             FROM marketplace_sale_mappings m
             JOIN pl_categories c ON c.id = m.pl_category_id
             WHERE m.company_id = :companyId',
            ['companyId' => $company->getId()],
        );
    }

    private function insertManualMapping(Company $company, string $operationType, string $amountSource, string $plCode, bool $isNegative, bool $isActive = true): void
    {
        $connection = $this->em()->getConnection();
        $plCategoryId = (string) $connection->fetchOne(
            'SELECT id FROM pl_categories WHERE company_id = :companyId AND code = :code',
            ['companyId' => $company->getId(), 'code' => $plCode],
        );

        $connection->insert('marketplace_sale_mappings', [
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'company_id' => $company->getId(),
            'marketplace' => 'ozon',
            'operation_type' => $operationType,
            'amount_source' => $amountSource,
            'pl_category_id' => $plCategoryId,
            'is_negative' => $isNegative ? 'true' : 'false',
            'sort_order' => 0,
            'is_active' => $isActive ? 'true' : 'false',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /** @return array{0: User, 1: Company} */
    private function seedBaseData(bool $withPlCodes = true, string $email = 'default-sale-mapping@test.local', int $index = 1): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $em = $this->em();
        $em->persist($user);
        $em->persist($company);

        if ($withPlCodes) {
            foreach (self::EXPECTED_OZON as [$code]) {
                $pl = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($code)->build();
                $pl->setCode($code);
                $pl->setType(PLCategoryType::LEAF_INPUT);
                $em->persist($pl);
            }
        }

        $em->flush();

        return [$user, $company];
    }
}
