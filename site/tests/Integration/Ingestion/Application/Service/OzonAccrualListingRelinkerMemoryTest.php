<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Service;

use App\Ingestion\Application\Service\OzonAccrualListingRelinker;
use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Infrastructure\Query\OzonAccrualProjectionHealthQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Регрессия GlitchTip #422: сверка Ozon падала по memory_limit 1G каждую ночь.
 * Восстановление контекста листинга держало в памяти контекст КАЖДОЙ строки каждого
 * снимка, на который ссылалась порция (до 5000 транзакций → сотни снимков), а проверка
 * покрытия слала все ключи снимка одним IN и упиралась в лимит 65 535 параметров.
 */
final class OzonAccrualListingRelinkerMemoryTest extends IntegrationTestCase
{
    public function testRecoversListingContextOnlyForTransactionsOfTheBatch(): void
    {
        $companyId = $this->companyId();
        $first = $this->storeSnapshot($companyId, 'day-1', 1000);
        $second = $this->storeSnapshot($companyId, 'day-2', 2000);
        [$firstKey, $firstSku] = $this->sourceKeyAt($companyId, $first, 5);
        [$secondKey, $secondSku] = $this->sourceKeyAt($companyId, $second, 7);

        $relinker = self::getContainer()->get(OzonAccrualListingRelinker::class);
        $recovered = (new \ReflectionMethod($relinker, 'recoverListingSourceData'))->invoke($relinker, [
            ['company_id' => $companyId, 'raw_record_id' => $first, 'external_id' => $firstKey, 'source_data' => '{}'],
            ['company_id' => $companyId, 'raw_record_id' => $second, 'external_id' => $secondKey, 'source_data' => '{}'],
            // Контекст уже есть — снимок за ним не читается.
            ['company_id' => $companyId, 'raw_record_id' => $second, 'external_id' => 'already', 'source_data' => '{"sku":"1","offer_id":"A"}'],
            // Снимка нет — ничего не восстанавливается, остальные не страдают.
            ['company_id' => $companyId, 'raw_record_id' => Uuid::uuid7()->toString(), 'external_id' => 'missing', 'source_data' => '{}'],
        ]);

        self::assertSame([$companyId => [
            $first => [$firstKey => ['sku' => $firstSku, 'offer_id' => 'ART-5', 'name' => 'Товар 5']],
            $second => [$secondKey => ['sku' => $secondSku, 'offer_id' => 'ART-7', 'name' => 'Товар 7']],
        ]], $recovered);
    }

    public function testProjectionCoverageQueriesMoreKeysThanPostgresParameterLimit(): void
    {
        $companyId = $this->companyId();
        $keys = array_map(static fn (int $i): string => 'ozon:accrual:'.$i, range(1, 70_000));

        $query = self::getContainer()->get(OzonAccrualProjectionHealthQuery::class);

        self::assertSame([], $query->projectedExternalIdSet($companyId, 'shop-1', $keys));
        self::assertSame(0, $query->reattributeProjectedExternalIds($companyId, 'shop-1', Uuid::uuid7()->toString(), new \DateTimeImmutable('2026-06-08'), $keys));
    }

    private function companyId(): string
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('relink-memory@example.com')->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return (string) $company->getId();
    }

    private function storeSnapshot(string $companyId, string $externalId, int $base): string
    {
        $rows = [];
        for ($i = 0; $i < 50; ++$i) {
            $rows[] = ['accrual_id' => $base + $i, 'date' => '2026-06-01', 'unit_number' => 'u-'.$i, 'accrued_category' => 'ITEM',
                'item_fees' => ['fees' => [['sku' => (string) (700000 + $base + $i), 'offer_id' => 'ART-'.$i, 'name' => 'Товар '.$i, 'fees' => [
                    ['type_id' => 1, 'name' => 'Acquiring', 'accrued' => ['amount' => '-12.34', 'currency' => 'RUB']],
                ]]]]];
        }

        return (string) self::getContainer()->get(RawStorageFacade::class)->store(new RawBatch(
            companyId: $companyId, connectionRef: 'shop-1', shopRef: 'shop-1', source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY, externalId: $externalId, syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable('2026-06-08 03:00:00+00:00'), rows: $rows,
        ))[0]->getId();
    }

    /**
     * @return array{string, string}
     */
    private function sourceKeyAt(string $companyId, string $rawRecordId, int $index): array
    {
        $rows = self::getContainer()->get(OzonAccrualByDayPreviewMapper::class)->preview(
            $companyId,
            self::getContainer()->get(RawStorageFacade::class)->read($rawRecordId, $companyId),
            includeSaleRefund: true,
        );

        return [$rows[$index]->sourceKey, (string) $rows[$index]->marketplaceSku];
    }
}
