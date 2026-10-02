<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Application\Processor;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Application\Processor\OzonAccrualReturnsRawProcessor;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Случай «ИП Лазарева», сентябрь 2026: историческая запись возврата (9 242 ₽, без метки, привязана к закрытому периоду)
 * и два начисления того же отправления в тот же день — 16 346 ₽ и 9 242 ₽. Теряется только то, чего нет в учёте.
 */
final class OzonAccrualReturnsLegacyRowTest extends IntegrationTestCase
{
    private const BASE = 'ozon-accrual-0156228731-0949-3-return-product-0';

    private string $companyId;
    private string $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('308866704')->build();
        $this->em->persist($listing);
        $this->em->flush();

        $this->companyId = (string) $company->getId();
        $this->listingId = (string) $listing->getId();
    }

    public function testLegacyRowIsKeptAndOnlyTheMissingAccrualIsAdded(): void
    {
        $this->connection->insert('marketplace_returns', [
            'id' => Uuid::uuid4()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId, 'marketplace' => 'ozon',
            'external_return_id' => self::BASE, 'return_date' => '2026-09-27', 'quantity' => 1, 'refund_amount' => '9242.00',
            'raw_data' => json_encode(['sku' => '308866704'], \JSON_THROW_ON_ERROR), 'created_at' => '2026-09-28 00:00:00', 'updated_at' => '2026-09-28 00:00:00',
        ]);
        $processor = self::getContainer()->get(OzonAccrualReturnsRawProcessor::class);
        $rows = [$this->accrual(70000000001, '16346'), $this->accrual(70000000002, '9242')];

        $processor->processBatch($this->companyId, MarketplaceType::OZON, $rows, null);
        $this->em->clear();

        self::assertSame('25588.00', $this->totalRefund());
        self::assertSame(2, $this->rowCount());
        // Историческая запись 9 242 осталась и получила метку подходящего начисления, 16 346 добавлено под суффиксным ключом.
        self::assertSame('70000000002', $this->connection->fetchOne('SELECT raw_data ->> \'_accrual_id\' FROM marketplace_returns WHERE external_return_id = :k', ['k' => self::BASE]));
        self::assertSame('16346.00', $this->connection->fetchOne('SELECT refund_amount FROM marketplace_returns WHERE external_return_id = :k', ['k' => self::BASE.'-acc70000000001']));

        // Повторный прогон и перестановка начислений ничего не меняют.
        $processor->processBatch($this->companyId, MarketplaceType::OZON, array_reverse($rows), null);
        $this->em->clear();

        self::assertSame('25588.00', $this->totalRefund());
        self::assertSame(2, $this->rowCount());
    }

    /**
     * @return array<string, mixed>
     */
    private function accrual(int $accrualId, string $amount): array
    {
        return [
            'accrual_id' => $accrualId, 'date' => '2026-09-27', 'unit_number' => '0156228731-0949-3', 'accrued_category' => 'POSTING',
            'posting' => ['products' => [[
                'sku' => '308866704', 'delivery' => null,
                'commission' => ['seller_price' => ['amount' => '-'.$amount], 'sale_price' => ['amount' => '-500'], 'sale_amount' => ['amount' => '-'.$amount], 'commission' => ['amount' => '0']],
            ]]],
        ];
    }

    private function totalRefund(): string
    {
        return (string) $this->connection->fetchOne('SELECT SUM(refund_amount)::numeric(12,2)::text FROM marketplace_returns WHERE company_id = :c', ['c' => $this->companyId]);
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE company_id = :c', ['c' => $this->companyId]);
    }
}
