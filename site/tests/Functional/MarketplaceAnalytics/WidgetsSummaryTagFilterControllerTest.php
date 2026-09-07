<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Company\Entity\User;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Виджеты на /marketplace-analytics/unit-extended обязаны считать тот же набор
 * листингов, что и таблица под ними. До фикса они игнорировали фильтр по тегам:
 * карточка «Выручка» показывала сумму по всей компании, а строка «Итого» —
 * по отфильтрованным листингам, и две цифры на одном экране противоречили друг другу.
 */
final class WidgetsSummaryTagFilterControllerTest extends WebTestCaseBase
{
    private const WIDGETS_URL = '/api/marketplace-analytics/unit-extended/widgets';
    private const TABLE_URL = '/api/marketplace-analytics/unit-extended';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000951';

    private const TAGGED_REVENUE = 1000.0;
    private const UNTAGGED_REVENUE = 9000.0;

    public function testWidgetsRevenueCountsOnlyTaggedListings(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $tagId] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $unfiltered = $this->requestWidgets($client, null);
        self::assertSame(
            self::TAGGED_REVENUE + self::UNTAGGED_REVENUE,
            (float) $unfiltered['current']['revenue'],
            'Без фильтра виджет обязан показывать выручку по всем листингам',
        );

        $filtered = $this->requestWidgets($client, $tagId);
        self::assertSame(
            self::TAGGED_REVENUE,
            (float) $filtered['current']['revenue'],
            'С фильтром по тегу виджет обязан считать только листинги с этим тегом',
        );
    }

    /**
     * Главный инвариант: виджет и строка «Итого» под ним считают одно и то же.
     */
    public function testWidgetRevenueMatchesTableTotalsUnderSameTagFilter(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $tagId] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $widgets = $this->requestWidgets($client, $tagId);

        $client->request('GET', self::TABLE_URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
            'tags' => [$tagId],
        ]);
        self::assertResponseIsSuccessful();
        $table = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(
            (float) $table['totals']['revenue'],
            (float) $widgets['current']['revenue'],
            'Выручка виджета и totals.revenue таблицы под одним фильтром обязаны совпадать',
        );
    }

    /**
     * Предыдущий период сравнивается по тому же набору листингов — иначе
     * дельта «пред: N ₽» сопоставляла бы разные корзины товаров.
     */
    public function testPreviousPeriodUsesSameTagFilter(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $tagId] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $filtered = $this->requestWidgets($client, $tagId);

        // В марте продаж нет ни у одного листинга — но важно, что предыдущий
        // период вообще посчитан по отфильтрованному набору, а не по всей компании.
        self::assertSame(0.0, (float) $filtered['previous']['revenue']);
    }

    public function testRejectsNonUuidTag(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', self::WIDGETS_URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
            'tags' => ['not-a-uuid'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestWidgets(KernelBrowser $client, ?string $tagId): array
    {
        $query = [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
        ];
        if (null !== $tagId) {
            $query['tags'] = [$tagId];
        }

        $client->request('GET', self::WIDGETS_URL, $query);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function seed(): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('widgets-tag-filter@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Widgets Tag Filter Co')
            ->build();

        $tagged = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-widget-tagged')
            ->build();

        $untagged = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-widget-untagged')
            ->build();

        $tag = MarketplaceListingTagBuilder::aTag()
            ->forCompanyId(self::COMPANY_ID)
            ->withName('Хит')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($tagged);
        $em->persist($untagged);
        $em->persist($tag);

        $em->persist(
            MarketplaceSaleBuilder::aSale()
                ->forCompany($company)
                ->forListing($tagged)
                ->withMarketplace(MarketplaceType::OZON)
                ->withSaleDate(new \DateTimeImmutable('2026-04-15'))
                ->withQuantity(1)
                ->withPricePerUnit('1000.00')
                ->withTotalRevenue('1000.00')
                ->build(),
        );

        $em->persist(
            MarketplaceSaleBuilder::aSale()
                ->forCompany($company)
                ->forListing($untagged)
                ->withMarketplace(MarketplaceType::OZON)
                ->withSaleDate(new \DateTimeImmutable('2026-04-15'))
                ->withQuantity(9)
                ->withPricePerUnit('1000.00')
                ->withTotalRevenue('9000.00')
                ->build(),
        );

        $em->flush();

        $em->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
             VALUES (?, ?, ?, NOW())',
            [$tagged->getId(), $tag->getId(), self::COMPANY_ID],
        );

        return [$owner, $tag->getId()];
    }
}
