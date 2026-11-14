<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceEconomics;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceEconomics\Command\EconomicsHealthCheckCommand;
use App\MarketplaceEconomics\Command\RefreshEconomicsCommand;
use App\MarketplaceOperations\Application\DTO\DayOperations;
use App\MarketplaceOperations\Application\DTO\OperationInput;
use App\MarketplaceOperations\Enum\OperationKind;
use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshEconomicsCommandTest extends IntegrationTestCase
{
    private const CABINET = 'cabinet-1';

    private string $companyId;
    private string $listingId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('me-'.$this->companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $this->listingId = Uuid::uuid7()->toString();
        $listing = MarketplaceListingBuilder::aListing()->withId($this->listingId)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('700')->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($listing);
        $this->em->flush();

        // себестоимость 300 ₽ до 09.11, с 10.11 — 350 ₽ (D5: цена на дату продажи)
        foreach ([['2026-01-01', '2026-11-09', '300.00'], ['2026-11-10', null, '350.00']] as [$from, $to, $price]) {
            $this->connection->insert('marketplace_inventory_cost_prices', [
                'id' => Uuid::uuid7()->toString(), 'company_id' => $this->companyId, 'listing_id' => $this->listingId,
                'effective_from' => $from, 'effective_to' => $to, 'price_amount' => $price, 'price_currency' => 'RUB', 'created_at' => '2026-11-01 00:00:00',
            ]);
        }
    }

    public function testRefreshesChangedDaysAndRepeatChangesNothing(): void
    {
        $this->canon('2026-11-09', 2);
        $this->canon('2026-11-10', 1);

        self::assertStringContainsString('2 days, 2 refreshed, 0 failed', $this->refresh());

        $days = $this->connection->fetchAllAssociativeIndexed(
            'SELECT operation_date::text, canon_minor, unallocated_minor, cost_price_minor, without_cost_price FROM me_day WHERE company_id = ? ORDER BY operation_date',
            [$this->companyId],
        );
        // 2 × 1000 − 2 × 150 − 50 хранения; себестоимость 2 × 300 / 1 × 350
        self::assertSame(['canon_minor' => 165000, 'unallocated_minor' => 0, 'cost_price_minor' => -60000, 'without_cost_price' => 0], $days['2026-11-09']);
        self::assertSame(-35000, $days['2026-11-10']['cost_price_minor']);
        // хранение без SKU разнесено на единственный SKU с выручкой
        self::assertSame(-5000, (int) $this->connection->fetchOne(
            "SELECT amount_minor FROM me_day_line WHERE company_id = ? AND operation_date = '2026-11-09' AND listing_id = ? AND measure = 'overhead_share'",
            [$this->companyId, $this->listingId],
        ));

        $lines = $this->rows();
        self::assertStringContainsString('0 days', $this->refresh(), 'отметки сняты');
        self::assertStringContainsString('2 days, 2 refreshed', $this->refresh(['--from' => '2026-11-01', '--to' => '2026-11-30']));
        self::assertSame($lines, $this->rows(), 'повторный пересчёт — то же число строк');
        self::assertSame(Command::SUCCESS, $this->health());
    }

    public function testReplacedDayIsRefreshedAgain(): void
    {
        $this->canon('2026-11-10', 2);
        $this->refresh();

        $this->canon('2026-11-10', 1);
        self::assertStringContainsString('1 days, 1 refreshed', $this->refresh());
        self::assertSame(-35000, (int) $this->connection->fetchOne('SELECT cost_price_minor FROM me_day WHERE company_id = ?', [$this->companyId]));
    }

    public function testGateCatchesUnbalancedDay(): void
    {
        $this->canon('2026-11-10', 1);
        $this->refresh();
        $this->connection->executeStatement("UPDATE me_day_line SET amount_minor = amount_minor + 1 WHERE company_id = ? AND measure = 'sale'", [$this->companyId]);

        self::assertSame(Command::FAILURE, $this->health());
    }

    private function canon(string $date, int $sales): void
    {
        $money = static fn (int $minor): Money => Money::fromMinor($minor, 'RUB');
        $ops = [new OperationInput(OperationKind::STORAGE, 'ozon:service:46', $money(-5000), ['s'])];
        for ($i = 0; $i < $sales; ++$i) {
            $ops[] = new OperationInput(OperationKind::SALE, 'ozon:field:sale_amount', $money(100000), ['p'.$i], $this->listingId, 1);
            $ops[] = new OperationInput(OperationKind::COMMISSION, 'ozon:field:sale_commission', $money(-15000), ['p'.$i], $this->listingId);
        }
        $facade = self::getContainer()->get(MarketplaceOperationsFacadeInterface::class);
        $this->connection->transactional(fn () => $facade->replaceDay(new DayOperations($this->companyId, 'ozon', self::CABINET, new \DateTimeImmutable($date), 'test', $ops)));
    }

    /** @param array<string, string> $options */
    private function refresh(array $options = []): string
    {
        $tester = new CommandTester(self::getContainer()->get(RefreshEconomicsCommand::class));
        $tester->execute($options);

        return $tester->getDisplay();
    }

    private function health(): int
    {
        return (new CommandTester(self::getContainer()->get(EconomicsHealthCheckCommand::class)))->execute([]);
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM me_day_line WHERE company_id = ?', [$this->companyId]);
    }
}
