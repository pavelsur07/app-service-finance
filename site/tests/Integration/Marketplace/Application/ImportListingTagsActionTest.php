<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Marketplace\Application\DTO\ListingTagsFileRow;
use App\Marketplace\Application\ImportListingTagsAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\ListingTagsImportOutdatedException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ImportListingTagsActionTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000003160';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000003161';
    private const L1 = '33333333-3333-3333-3333-000000000001';
    private const L2 = '33333333-3333-3333-3333-000000000002';
    private const L3 = '33333333-3333-3333-3333-000000000003';
    private const FOREIGN = '33333333-3333-3333-3333-000000000009';
    private const TAG_WINTER = '44444444-4444-4444-4444-000000000001';
    private const TAG_SALE = '44444444-4444-4444-4444-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function testPreviewWritesNothing(): void
    {
        $result = $this->action()(self::COMPANY_ID, [new ListingTagsFileRow(2, self::L2, 'Лето')], false);

        self::assertFalse($result->applied);
        self::assertSame(1, $result->added());
        self::assertSame(['Лето'], $result->newTags);
        self::assertSame(1, $this->assignmentsCount());
        self::assertSame(2, $this->tagsCount());
    }

    public function testAddModeKeepsExistingTagsAndReusesTagBySlug(): void
    {
        $rows = [
            new ListingTagsFileRow(2, self::L1, ' зима ; Лето;лето;'),
            new ListingTagsFileRow(3, self::L2, 'ЗИМА'),
        ];
        $preview = $this->action()(self::COMPANY_ID, $rows, false);

        $result = $this->action()(self::COMPANY_ID, $rows, false, $preview->fingerprint);

        self::assertTrue($result->applied);
        // L1 уже с «Зима»: добавляется только «Лето»; L2 получает существующий «Зима».
        self::assertSame(2, $result->added());
        self::assertSame(0, $result->removed());
        self::assertSame(['Лето'], $result->newTags);
        self::assertSame(['Зима', 'Лето'], $this->tagNames(self::L1));
        self::assertSame(['Зима'], $this->tagNames(self::L2));
        self::assertSame(3, $this->tagsCount());
    }

    public function testReplaceModeTouchesOnlyRowsWithFilledCells(): void
    {
        $this->assign(self::L2, self::TAG_SALE);
        $this->assign(self::L3, self::TAG_SALE);
        $rows = [
            new ListingTagsFileRow(2, self::L1, 'Распродажа'), // Зима → Распродажа
            new ListingTagsFileRow(3, self::L2, ''),           // пусто — не трогать
        ];                                                    // L3 в файле нет — не трогать
        $preview = $this->action()(self::COMPANY_ID, $rows, true);

        $result = $this->action()(self::COMPANY_ID, $rows, true, $preview->fingerprint);

        self::assertSame(1, $result->added());
        self::assertSame(1, $result->removed());
        self::assertSame(['Распродажа'], $this->tagNames(self::L1));
        self::assertSame(['Распродажа'], $this->tagNames(self::L2));
        self::assertSame(['Распродажа'], $this->tagNames(self::L3));
    }

    public function testDashClearsAllTagsInAnyMode(): void
    {
        $this->assign(self::L1, self::TAG_SALE);
        $rows = [new ListingTagsFileRow(2, self::L1, ' - ')];
        $preview = $this->action()(self::COMPANY_ID, $rows, false);

        $result = $this->action()(self::COMPANY_ID, $rows, false, $preview->fingerprint);

        self::assertSame(2, $result->removed());
        self::assertSame([], $this->tagNames(self::L1));
        self::assertSame(2, $this->tagsCount(), 'Сами теги не удаляются');
    }

    public function testInvalidRowsAreReportedAndSkipped(): void
    {
        $rows = [
            new ListingTagsFileRow(2, self::FOREIGN, 'Лето'),
            new ListingTagsFileRow(3, 'не-uuid', 'Лето'),
            new ListingTagsFileRow(4, self::L2, str_repeat('я', 51)),
            new ListingTagsFileRow(5, self::L3, 'Лето'),
            new ListingTagsFileRow(6, strtoupper(self::L3), 'Осень'),
            new ListingTagsFileRow(7, '', ''),
        ];
        $preview = $this->action()(self::COMPANY_ID, $rows, false);

        self::assertSame([2, 3, 4, 6], array_column($preview->errors, 'row'));

        $result = $this->action()(self::COMPANY_ID, $rows, false, $preview->fingerprint);

        self::assertSame(1, $result->added());
        self::assertSame(['Лето'], $this->tagNames(self::L3));
        self::assertSame([], $this->tagNames(self::FOREIGN), 'Листинг чужой компании не меняется');
    }

    public function testRepeatedImportChangesNothing(): void
    {
        $rows = [new ListingTagsFileRow(2, self::L2, 'Зима; Лето')];
        $first = $this->action()(self::COMPANY_ID, $rows, true);
        $this->action()(self::COMPANY_ID, $rows, true, $first->fingerprint);
        $countAfterFirst = $this->assignmentsCount();

        $second = $this->action()(self::COMPANY_ID, $rows, true);
        $applied = $this->action()(self::COMPANY_ID, $rows, true, $second->fingerprint);

        self::assertTrue($second->isEmpty());
        self::assertSame(0, $applied->added() + $applied->removed());
        self::assertSame($countAfterFirst, $this->assignmentsCount());
        self::assertSame(3, $this->tagsCount());
    }

    public function testOutdatedFingerprintIsRejectedWithoutWrites(): void
    {
        $rows = [new ListingTagsFileRow(2, self::L2, 'Распродажа; Лето')];
        $preview = $this->action()(self::COMPANY_ID, $rows, false);
        $this->assign(self::L2, self::TAG_SALE); // кто-то назначил тег между предпросмотром и применением

        try {
            $this->action()(self::COMPANY_ID, $rows, false, $preview->fingerprint);
            self::fail('Ожидалось ListingTagsImportOutdatedException');
        } catch (ListingTagsImportOutdatedException) {
        }

        self::assertSame(['Распродажа'], $this->tagNames(self::L2));
        self::assertSame(2, $this->tagsCount(), '«Лето» не создан');
    }

    public function testTooManyRowsAreRejected(): void
    {
        $rows = array_map(
            static fn (int $i): ListingTagsFileRow => new ListingTagsFileRow($i + 2, self::L1, 'Зима'),
            range(1, ImportListingTagsAction::MAX_ROWS + 1),
        );

        $this->expectException(\DomainException::class);
        $this->action()(self::COMPANY_ID, $rows, false);
    }

    private function action(): ImportListingTagsAction
    {
        $container = self::getContainer();

        return new ImportListingTagsAction(
            $container->get(MarketplaceListingTagRepository::class),
            $container->get(ListingTagAssignmentRepository::class),
            $this->connection,
        );
    }

    private function seed(): void
    {
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000003160')->withEmail('import-tags@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000003161')->withEmail('import-tags-2@example.test')->build();
        $other = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build();
        foreach ([$owner, $company, $otherOwner, $other] as $entity) {
            $this->em->persist($entity);
        }

        foreach ([self::L1 => $company, self::L2 => $company, self::L3 => $company, self::FOREIGN => $other] as $id => $owning) {
            $this->em->persist(
                MarketplaceListingBuilder::aListing()
                    ->withId($id)
                    ->forCompany($owning)
                    ->withMarketplace(MarketplaceType::OZON)
                    ->withMarketplaceSku('sku-'.substr($id, -4))
                    ->withName('Листинг '.substr($id, -1))
                    ->build(),
            );
        }
        $this->em->persist(MarketplaceListingTagBuilder::aTag()->withId(self::TAG_WINTER)->forCompanyId(self::COMPANY_ID)->withName('Зима')->build());
        $this->em->persist(MarketplaceListingTagBuilder::aTag()->withId(self::TAG_SALE)->forCompanyId(self::COMPANY_ID)->withName('Распродажа')->build());
        $this->em->flush();

        $this->assign(self::L1, self::TAG_WINTER);
    }

    private function assign(string $listingId, string $tagId): void
    {
        self::getContainer()->get(ListingTagAssignmentRepository::class)->assign(self::COMPANY_ID, [$listingId], $tagId);
    }

    /**
     * @return list<string>
     */
    private function tagNames(string $listingId): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT t.name FROM marketplace_listing_tag_assignments a JOIN marketplace_listing_tags t ON t.id = a.tag_id
             WHERE a.listing_id = :id ORDER BY t.name',
            ['id' => $listingId],
        );

        return $names;
    }

    private function assignmentsCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tag_assignments');
    }

    private function tagsCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tags WHERE company_id = :c', ['c' => self::COMPANY_ID]);
    }
}
