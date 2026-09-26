<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon;

use App\Company\Entity\Company;
use App\Finance\Entity\Document;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260926120000;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

require_once dirname(__DIR__, 4).'/migrations/Version20260926120000.php';

final class OzonUnclassifiedServicesMigrationTest extends IntegrationTestCase
{
    private const PREMIUM_UNKNOWN_NAME = 'Неразобранная услуга Ozon: PremiumSubscription';

    public function testMovesOnlyFreeRowsIntoTheCompanysOwnCategoryAndRollsBack(): void
    {
        $owner = UserBuilder::aUser()->build();
        $open = CompanyBuilder::aCompany()->withIndex(926)->withOwner($owner)->build();
        $locked = CompanyBuilder::aCompany()->withIndex(927)->withOwner($owner)->build();
        $locked->setFinanceLockBefore(new \DateTimeImmutable('2026-09-15'));
        $this->em->persist($owner);
        $this->em->persist($open);
        $this->em->persist($locked);

        $openPremium = $this->category($open, 'ozon_premium_promotion', 'Продвижение Premium Ozon');
        $openUnknown52 = $this->category($open, 'ozon_unknown_52', self::PREMIUM_UNKNOWN_NAME);
        $this->category($open, 'ozon_temporary_storage', 'Временное хранение товара Ozon');
        $openUnknown78 = $this->category($open, 'ozon_unknown_78', 'Неразобранная услуга Ozon: TemporaryPlacement');
        $lockedPremium = $this->category($locked, 'ozon_premium_promotion', 'Продвижение Premium Ozon');
        $lockedUnknown52 = $this->category($locked, 'ozon_unknown_52', self::PREMIUM_UNKNOWN_NAME);
        // Целевой категории нет — строка остаётся неразобранной, а не теряется.
        $lockedUnknown78 = $this->category($locked, 'ozon_unknown_78', 'Неразобранная услуга Ozon: TemporaryPlacement');

        $document = new Document(Uuid::uuid4()->toString(), $open);
        $this->em->persist($document);

        $moved = $this->cost($open, $openUnknown52, 'ozon-accrual-1-non-item-type-52', '2026-09-11', self::PREMIUM_UNKNOWN_NAME);
        $inDocument = $this->cost($open, $openUnknown78, 'ozon-accrual-2-item-fee-0-type-78', '2026-09-20', 'ручное описание')
            ->setDocument($document);
        $beforeLock = $this->cost($locked, $lockedUnknown52, 'ozon-accrual-3-non-item-type-52', '2026-09-14', self::PREMIUM_UNKNOWN_NAME);
        $afterLock = $this->cost($locked, $lockedUnknown52, 'ozon-accrual-4-non-item-type-52', '2026-09-25', 'своё описание');
        $noTarget = $this->cost($locked, $lockedUnknown78, 'ozon-accrual-5-item-fee-0-type-78', '2026-09-20', 'x');
        $legacyPremium = $this->cost($open, $openPremium, 'legacy-premium-1', '2026-08-25', 'Продвижение Premium Ozon');
        $this->em->flush();

        $totalBefore = $this->totalAmount();

        $this->applyMigration(fn (Version20260926120000 $m) => $m->up(new Schema()));

        self::assertSame($openPremium->getId(), $this->categoryOf($moved));
        self::assertSame('Продвижение Premium Ozon', $this->descriptionOf($moved));
        self::assertSame($lockedPremium->getId(), $this->categoryOf($afterLock));
        self::assertSame('своё описание', $this->descriptionOf($afterLock));
        self::assertSame($openUnknown78->getId(), $this->categoryOf($inDocument));
        self::assertSame($lockedUnknown52->getId(), $this->categoryOf($beforeLock));
        self::assertSame($lockedUnknown78->getId(), $this->categoryOf($noTarget));
        self::assertSame($totalBefore, $this->totalAmount());

        self::assertTrue($this->isDeleted($openUnknown52));
        self::assertFalse($this->isDeleted($openUnknown78));
        self::assertFalse($this->isDeleted($lockedUnknown52));

        $this->applyMigration(fn (Version20260926120000 $m) => $m->down(new Schema()));

        self::assertSame($openUnknown52->getId(), $this->categoryOf($moved));
        self::assertSame(self::PREMIUM_UNKNOWN_NAME, $this->descriptionOf($moved));
        self::assertSame($lockedUnknown52->getId(), $this->categoryOf($afterLock));
        self::assertSame($openPremium->getId(), $this->categoryOf($legacyPremium));
        self::assertFalse($this->isDeleted($openUnknown52));
        self::assertSame($totalBefore, $this->totalAmount());
    }

    /**
     * @param callable(Version20260926120000): void $step
     */
    private function applyMigration(callable $step): void
    {
        $migration = new Version20260926120000($this->connection, new NullLogger());
        $step($migration);
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function category(Company $company, string $code, string $name): MarketplaceCostCategory
    {
        $category = (new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON))
            ->setCode($code)
            ->setName($name);
        $this->em->persist($category);

        return $category;
    }

    private function cost(Company $company, MarketplaceCostCategory $category, string $externalId, string $date, string $description): MarketplaceCost
    {
        $cost = (new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON, $category))
            ->setAmount('24990.00')
            ->setCostDate(new \DateTimeImmutable($date))
            ->setExternalId($externalId)
            ->setDescription($description)
            ->setOperationType(MarketplaceCostOperationType::CHARGE);
        $this->em->persist($cost);

        return $cost;
    }

    private function categoryOf(MarketplaceCost $cost): string
    {
        return (string) $this->connection->fetchOne('SELECT category_id FROM marketplace_costs WHERE id = :id', ['id' => $cost->getId()]);
    }

    private function descriptionOf(MarketplaceCost $cost): string
    {
        return (string) $this->connection->fetchOne('SELECT description FROM marketplace_costs WHERE id = :id', ['id' => $cost->getId()]);
    }

    private function isDeleted(MarketplaceCostCategory $category): bool
    {
        return null !== $this->connection->fetchOne('SELECT deleted_at FROM marketplace_cost_categories WHERE id = :id', ['id' => $category->getId()]);
    }

    private function totalAmount(): string
    {
        return (string) $this->connection->fetchOne('SELECT SUM(amount) FROM marketplace_costs');
    }
}
