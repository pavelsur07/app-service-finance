<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Infrastructure\Query;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\OzonListingCatalogUpsertQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonListingCatalogUpsertQueryTest extends IntegrationTestCase
{
    private OzonListingCatalogUpsertQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        // Напрямую, а не из контейнера: у запроса одна зависимость — Connection,
        // а сам сервис инлайнится компилятором, пока на него нет ссылок.
        // Проводку через DI покрывает тест Action'а.
        $this->query = new OzonListingCatalogUpsertQuery($this->connection);
    }

    /**
     * Товар, по которому ещё не было ни одной финансовой операции: строки нет,
     * каталог обязан её завести. До задачи такой товар был невидим.
     */
    public function testCreatesListingForProductThatNeverHadSales(): void
    {
        $company = $this->seedCompany(51);
        $this->em->flush();

        $this->upsert($company, '900000001', 'Новый товар', 'ART-NEW');

        $row = $this->fetchListing($company, '900000001');

        self::assertSame('Новый товар', $row['name']);
        self::assertSame('ART-NEW', $row['supplier_sku']);
        self::assertSame('UNKNOWN', $row['size']);
        self::assertTrue((bool) $row['is_active']);
        self::assertSame('2021-08-24 14:15:19', $row['marketplace_created_at']);
        self::assertNotNull($row['last_seen_at']);
    }

    /**
     * Главный дефект задачи: финансовый pipeline создаёт листинг с name = NULL
     * (ON CONFLICT DO NOTHING), и никакой последующий батч его не заполняет.
     */
    public function testFillsNameOfListingLeftNullByFinancialPipeline(): void
    {
        $company = $this->seedCompany(52);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('900000002')
            ->build();
        $this->em->persist($listing);
        $this->em->flush();

        self::assertNull($listing->getName());

        $this->upsert($company, '900000002', 'Имя из каталога', 'ART-002');

        self::assertSame('Имя из каталога', $this->fetchListing($company, '900000002')['name']);
    }

    /**
     * Решение Владельца: пропажу из каталога разбираем вручную. Каталог не
     * управляет флагом активности ни в одну сторону.
     */
    public function testDoesNotTouchIsActive(): void
    {
        $company = $this->seedCompany(53);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('900000003')
            ->build();
        $listing->setIsActive(false);
        $this->em->persist($listing);
        $this->em->flush();

        $this->upsert($company, '900000003', 'Имя', 'ART-003');

        self::assertFalse((bool) $this->fetchListing($company, '900000003')['is_active']);
    }

    /**
     * Каталожная цена — витринная, а не цена продажи. Перезапись сменила бы
     * смысл колонки, поэтому она идёт только в marketplace_data.
     */
    public function testDoesNotTouchPriceColumnButKeepsCatalogPriceInJson(): void
    {
        $company = $this->seedCompany(54);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('900000004')
            ->build();
        $listing->setPrice('1234.56');
        $this->em->persist($listing);
        $this->em->flush();

        $this->upsert($company, '900000004', 'Имя', 'ART-004');

        $row = $this->fetchListing($company, '900000004');
        self::assertSame('1234.56', $row['price']);

        $data = json_decode((string) $row['marketplace_data'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('3500.00', $data['price']);
    }

    public function testCatalogWithoutNameDoesNotEraseExistingName(): void
    {
        $company = $this->seedCompany(55);
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('900000005')
            ->build();
        $listing->setName('Имя из финансового документа');
        $this->em->persist($listing);
        $this->em->flush();

        $this->upsert($company, '900000005', null, null);

        $row = $this->fetchListing($company, '900000005');
        self::assertSame('Имя из финансового документа', $row['name']);
    }

    public function testAnotherCompanyWithSameSkuIsNotTouched(): void
    {
        $companyA = $this->seedCompany(56);
        $companyB = $this->seedCompany(57);
        $foreign = MarketplaceListingBuilder::aListing()
            ->forCompany($companyB)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('900000006')
            ->build();
        $this->em->persist($foreign);
        $this->em->flush();

        $this->upsert($companyA, '900000006', 'Имя компании A', 'ART-006');

        self::assertNull($this->fetchListing($companyB, '900000006')['name']);
        self::assertSame('Имя компании A', $this->fetchListing($companyA, '900000006')['name']);
    }

    public function testSecondRunCreatesNoDuplicate(): void
    {
        $company = $this->seedCompany(58);
        $this->em->flush();

        $this->upsert($company, '900000007', 'Имя', 'ART-007');
        $this->upsert($company, '900000007', 'Имя', 'ART-007');

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
            ['company' => $company->getId(), 'sku' => '900000007'],
        ));
    }

    /**
     * Два прогона могут идти внахлёст (ночной cron и ручной запуск). Прогон,
     * начавшийся раньше, но завершившийся позже, не должен откатывать
     * last_seen_at назад: поле означает «когда листинг видели в каталоге
     * последний раз», и движение назад делает его ложью.
     */
    public function testLateFinishingOlderRunDoesNotMoveLastSeenAtBackwards(): void
    {
        $company = $this->seedCompany(59);
        $this->em->flush();

        $this->upsertSeenAt($company, '900000008', new \DateTimeImmutable('2026-09-01T04:00:00+00:00'));
        $this->upsertSeenAt($company, '900000008', new \DateTimeImmutable('2026-09-01T03:40:00+00:00'));

        self::assertSame(
            '2026-09-01 04:00:00',
            $this->fetchListing($company, '900000008')['last_seen_at'],
        );
    }

    /**
     * Отметка времени — не единственное, что нельзя откатывать. Запоздавший
     * старый прогон не должен подменять и содержимое снимка: иначе строка
     * выглядит свежей по last_seen_at, а несёт устаревшие имя, цену и статус.
     */
    public function testLateFinishingOlderRunDoesNotOverwriteFresherSnapshot(): void
    {
        $company = $this->seedCompany(60);
        $this->em->flush();

        $this->upsertSnapshot($company, '900000009', 'Свежее имя', new \DateTimeImmutable('2026-09-01T04:00:00+00:00'));
        $this->upsertSnapshot($company, '900000009', 'Устаревшее имя', new \DateTimeImmutable('2026-09-01T03:40:00+00:00'));

        $row = $this->fetchListing($company, '900000009');
        self::assertSame('Свежее имя', $row['name']);
        self::assertSame('2026-09-01 04:00:00', $row['last_seen_at']);
    }

    /**
     * Колонка last_seen_at имеет точность в секунду, поэтому два прогона,
     * стартовавшие в одну секунду, получают одинаковую отметку. При нестрогом
     * сравнении завершившийся последним перезаписал бы чужой снимок, и guard
     * не сработал бы. Внутри одного прогона повтора нет: у двух листингов
     * одного товара разные marketplace_sku, то есть разные conflict-ключи.
     */
    public function testConcurrentRunWithTheSameTimestampDoesNotOverwrite(): void
    {
        $company = $this->seedCompany(70);
        $this->em->flush();

        $seenAt = new \DateTimeImmutable('2026-09-01T03:40:00+00:00');
        $this->upsertSnapshot($company, '900000010', 'Первое', $seenAt);
        $this->upsertSnapshot($company, '900000010', 'Второе', $seenAt);

        self::assertSame('Первое', $this->fetchListing($company, '900000010')['name']);
    }

    /**
     * Счётчик обязан отражать записанное, а не предпринятое. Отклонённый
     * freshness-guard'ом upsert не должен попадать в listings_upserted:
     * иначе лог утверждает больше, чем произошло, и прячет срабатывание
     * защиты.
     */
    public function testRejectedStaleUpsertReportsNoAffectedRows(): void
    {
        $company = $this->seedCompany(72);
        $this->em->flush();

        $fresh = $this->query->upsert(
            companyId: (string) $company->getId(),
            marketplaceSku: '900000011',
            name: 'Свежее',
            supplierSku: 'ART',
            marketplaceCreatedAt: null,
            lastSeenAt: new \DateTimeImmutable('2026-09-01T04:00:00+00:00'),
            marketplaceData: [],
        );
        $stale = $this->query->upsert(
            companyId: (string) $company->getId(),
            marketplaceSku: '900000011',
            name: 'Устаревшее',
            supplierSku: 'ART',
            marketplaceCreatedAt: null,
            lastSeenAt: new \DateTimeImmutable('2026-09-01T03:40:00+00:00'),
            marketplaceData: [],
        );

        self::assertSame(1, $fresh);
        self::assertSame(0, $stale);
    }

    private function upsertSnapshot(Company $company, string $sku, string $name, \DateTimeImmutable $lastSeenAt): void
    {
        $this->query->upsert(
            companyId: (string) $company->getId(),
            marketplaceSku: $sku,
            name: $name,
            supplierSku: 'ART',
            marketplaceCreatedAt: null,
            lastSeenAt: $lastSeenAt,
            marketplaceData: ['price' => $name],
        );
    }

    private function upsertSeenAt(Company $company, string $sku, \DateTimeImmutable $lastSeenAt): void
    {
        $this->query->upsert(
            companyId: (string) $company->getId(),
            marketplaceSku: $sku,
            name: 'Имя',
            supplierSku: 'ART',
            marketplaceCreatedAt: null,
            lastSeenAt: $lastSeenAt,
            marketplaceData: [],
        );
    }

    private function upsert(Company $company, string $sku, ?string $name, ?string $supplierSku): void
    {
        $this->query->upsert(
            companyId: (string) $company->getId(),
            marketplaceSku: $sku,
            name: $name,
            supplierSku: $supplierSku,
            marketplaceCreatedAt: new \DateTimeImmutable('2021-08-24T14:15:19+00:00'),
            lastSeenAt: new \DateTimeImmutable('2026-09-01T03:40:00+00:00'),
            marketplaceData: ['price' => '3500.00', 'status_name' => 'Продается'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchListing(Company $company, string $sku): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT name, supplier_sku, size, is_active, price, marketplace_data, marketplace_created_at, last_seen_at
             FROM marketplace_listings WHERE company_id = :company AND marketplace_sku = :sku',
            ['company' => $company->getId(), 'sku' => $sku],
        );

        self::assertIsArray($row);

        return $row;
    }

    private function seedCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($owner)
            ->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}
