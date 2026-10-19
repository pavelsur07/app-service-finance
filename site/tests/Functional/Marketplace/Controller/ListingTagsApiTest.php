<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Security\AccessLevel;
use App\Company\Security\Module;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ListingTagsApiTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000901';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000902';

    private const ASSIGN_URL = '/api/marketplace/listings/tags/assign';
    private const DETACH_URL = '/api/marketplace/listings/tags/detach';

    public function testCreatesTagOnTheFlyAndAssignsItToSelectedListings(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(3);
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => array_map(static fn (MarketplaceListing $l): string => $l->getId(), $listings),
            'name' => '  Зима  ',
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($client);
        self::assertSame('Зима', $payload['tagName']);
        self::assertSame(3, $payload['assigned']);

        self::assertSame('зима', $this->fetchOne(
            'SELECT slug FROM marketplace_listing_tags WHERE company_id = ?',
            [self::COMPANY_ID],
        ));
        self::assertSame(3, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE company_id = ?',
            [self::COMPANY_ID],
        ));
    }

    public function testAssignsExistingTagById(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(1);
        $tag = $this->seedTag(self::COMPANY_ID, 'Распродажа');
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => [$listings[0]->getId()],
            'tagId' => $tag,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jsonResponse($client)['assigned']);
    }

    public function testRepeatedAssignIsIdempotent(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(2);
        $this->login($client, $owner, $company);

        $listingIds = array_map(static fn (MarketplaceListing $l): string => $l->getId(), $listings);

        $this->postJson($client, self::ASSIGN_URL, ['listingIds' => $listingIds, 'name' => 'Зима']);
        $tagId = $this->jsonResponse($client)['tagId'];

        $this->postJson($client, self::ASSIGN_URL, ['listingIds' => $listingIds, 'tagId' => $tagId]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->jsonResponse($client)['assigned']);
        self::assertSame(2, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ?',
            [$tagId],
        ));
    }

    /**
     * Параллельный запрос создаёт одноимённый тег между findBySlug() и вставкой:
     * назначение — это «найти или создать», поэтому листинги получают уже
     * существующий тег, а не 500 на uniq_listing_tag_company_slug.
     */
    public function testConcurrentCreateOfSameNameAssignsExistingTag(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(2);
        $this->login($client, $owner, $company);

        $concurrentTagId = Uuid::uuid7()->toString();
        $listener = new class($this->em()->getConnection(), self::COMPANY_ID, $concurrentTagId) {
            private bool $done = false;

            public function __construct(
                private readonly Connection $connection,
                private readonly string $companyId,
                private readonly string $tagId,
            ) {
            }

            public function preFlush(): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $this->connection->executeStatement(
                    'INSERT INTO marketplace_listing_tags (id, company_id, name, slug, created_at)
                     VALUES (?, ?, ?, ?, NOW()) ON CONFLICT (company_id, slug) DO NOTHING',
                    [$this->tagId, $this->companyId, 'зима', 'зима'],
                );
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::preFlush], $listener);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => array_map(static fn (MarketplaceListing $l): string => $l->getId(), $listings),
            'name' => 'Зима',
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($client);
        self::assertSame(2, $payload['assigned']);
        self::assertSame(1, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tags WHERE company_id = ?',
            [self::COMPANY_ID],
        ));
        self::assertSame(2, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ?',
            [$payload['tagId']],
        ));
    }

    public function testDoesNotTagListingOfAnotherCompany(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $foreignListingId = $this->seedForeignCompanyWithListing();
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => [$foreignListingId],
            'name' => 'Чужой',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->jsonResponse($client)['assigned']);
        self::assertSame(0, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE listing_id = ?',
            [$foreignListingId],
        ));
    }

    public function testDetachRemovesOnlyRequestedAssignment(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(2);
        $this->login($client, $owner, $company);

        $listingIds = array_map(static fn (MarketplaceListing $l): string => $l->getId(), $listings);
        $this->postJson($client, self::ASSIGN_URL, ['listingIds' => $listingIds, 'name' => 'Зима']);
        $tagId = $this->jsonResponse($client)['tagId'];

        $this->postJson($client, self::DETACH_URL, ['listingIds' => [$listingIds[0]], 'tagId' => $tagId]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jsonResponse($client)['detached']);
        self::assertSame(1, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ?',
            [$tagId],
        ));
    }

    public function testDetachDoesNotTouchAnotherCompanyAssignment(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $foreignListingId = $this->seedForeignCompanyWithListing();
        $foreignTagId = $this->seedTag(self::OTHER_COMPANY_ID, 'Чужой тег');
        $this->seedAssignment(self::OTHER_COMPANY_ID, $foreignListingId, $foreignTagId);
        $this->login($client, $owner, $company);

        $this->postJson($client, self::DETACH_URL, [
            'listingIds' => [$foreignListingId],
            'tagId' => $foreignTagId,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->jsonResponse($client)['detached']);
        self::assertSame(1, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ?',
            [$foreignTagId],
        ));
    }

    public function testReturns404ForUnknownTag(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(1);
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => [$listings[0]->getId()],
            'tagId' => '99999999-9999-4999-8999-999999999999',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('tag_not_found', $this->jsonResponse($client)['error']['code']);
    }

    public function testForeignCompanyTagIsNotReusable(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(1);
        $this->seedForeignCompanyWithListing();
        $foreignTagId = $this->seedTag(self::OTHER_COMPANY_ID, 'Чужой тег');
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => [$listings[0]->getId()],
            'tagId' => $foreignTagId,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * «Выбрать все N по фильтру»: тег получают все совпавшие листинги компании,
     * а не только 25 строк страницы, которые знает браузер.
     */
    public function testAssignsTagToEveryListingMatchingFilter(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompanyWithListings(30, 'Наволочки 50х70');
        $this->seedForeignCompanyWithListing('Наволочки чужие');
        $this->em()->clear();
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, ['filter' => ['q' => 'НАВОЛ'], 'name' => 'Склад 1']);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($client);
        self::assertSame(30, $payload['matched']);
        self::assertSame(30, $payload['assigned']);
        self::assertSame(30, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ? AND company_id = ?',
            [$payload['tagId'], self::COMPANY_ID],
        ));
        self::assertSame(0, (int) $this->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE company_id = ?',
            [self::OTHER_COMPANY_ID],
        ));

        $this->postJson($client, self::ASSIGN_URL, ['filter' => ['q' => 'навол'], 'tagId' => $payload['tagId']]);

        self::assertResponseIsSuccessful();
        $repeat = $this->jsonResponse($client);
        self::assertSame(30, $repeat['matched']);
        self::assertSame(0, $repeat['assigned']);
    }

    public function testFilterMatchingNothingAssignsNothing(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompanyWithListings(2, 'Плед');
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, ['filter' => ['q' => 'навол'], 'name' => 'Склад 1']);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($client);
        self::assertSame(0, $payload['matched']);
        self::assertSame(0, $payload['assigned']);
    }

    public function testFilterModeRejectsTagOfAnotherCompany(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompanyWithListings(2, 'Наволочки');
        $this->seedForeignCompanyWithListing();
        $foreignTag = $this->seedTag(self::OTHER_COMPANY_ID, 'Чужой');
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, ['filter' => ['q' => 'навол'], 'tagId' => $foreignTag]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tag_assignments', []));
    }

    public function testListingIdsModeCountsOnlyOwnListingsAsMatched(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(2);
        $foreignListingId = $this->seedForeignCompanyWithListing();
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, [
            'listingIds' => [$listings[0]->getId(), $listings[1]->getId(), $foreignListingId],
            'name' => 'Зима',
        ]);

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($client);
        self::assertSame(2, $payload['matched']);
        self::assertSame(2, $payload['assigned']);
    }

    public function testReadOnlyMemberCannotAssignByFilter(): void
    {
        $client = static::createClient();
        [, $company] = $this->seedCompanyWithListings(2, 'Наволочки');

        $role = new CompanyRole(
            Uuid::uuid4()->toString(),
            'Только чтение маркетплейса',
            [Module::MARKETPLACE->value => AccessLevel::READ->value],
            $company,
        );
        $member = UserBuilder::aUser()
            ->withIndex(79)
            ->withEmail('listing-tags-api-readonly@example.test')
            ->withRoles(['ROLE_COMPANY_USER'])
            ->build();

        $em = $this->em();
        $em->persist($role);
        $em->persist($member);
        $em->persist(
            CompanyMemberBuilder::aMember()
                ->withId(Uuid::uuid4()->toString())
                ->withCompany($company)
                ->withUser($member)
                ->withRole(CompanyMember::ROLE_OPERATOR)
                ->withStatus(CompanyMember::STATUS_ACTIVE)
                ->withAccessRole($role)
                ->build(),
        );
        $em->flush();
        $this->login($client, $member, $company);

        $this->postJson($client, self::ASSIGN_URL, ['filter' => ['q' => 'навол'], 'name' => 'Склад 1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tag_assignments', []));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $payload, string $expectedCode): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $this->login($client, $owner, $company);

        $this->postJson($client, self::ASSIGN_URL, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($expectedCode, $this->jsonResponse($client)['error']['code']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $validId = '33333333-3333-4333-8333-000000000001';

        yield 'no listing ids' => [['name' => 'Зима'], 'listing_ids_required'];
        yield 'empty listing ids' => [['listingIds' => [], 'name' => 'Зима'], 'listing_ids_required'];
        yield 'listing id not uuid' => [['listingIds' => ['nope'], 'name' => 'Зима'], 'listing_id_invalid'];
        yield 'too many listing ids' => [
            ['listingIds' => array_fill(0, 501, $validId), 'name' => 'Зима'],
            'listing_ids_limit_exceeded',
        ];
        yield 'both tag refs' => [
            ['listingIds' => [$validId], 'tagId' => $validId, 'name' => 'Зима'],
            'tag_reference_required',
        ];
        yield 'no tag ref' => [['listingIds' => [$validId]], 'tag_reference_required'];
        yield 'blank tag name' => [['listingIds' => [$validId], 'name' => '   '], 'tag_name_invalid'];
        yield 'long tag name' => [
            ['listingIds' => [$validId], 'name' => str_repeat('я', 51)],
            'tag_name_invalid',
        ];
        yield 'tag id not uuid' => [['listingIds' => [$validId], 'tagId' => 'nope'], 'tag_id_invalid'];
        yield 'listing ids and filter' => [
            ['listingIds' => [$validId], 'filter' => ['q' => 'навол'], 'name' => 'Зима'],
            'listing_selection_required',
        ];
        yield 'filter with unknown marketplace' => [
            ['filter' => ['marketplace' => 'amazon'], 'name' => 'Зима'],
            'filter_invalid',
        ];
        yield 'filter not an object' => [['filter' => 'навол', 'name' => 'Зима'], 'filter_invalid'];
    }

    /**
     * @return array{0: User, 1: Company, 2: list<MarketplaceListing>}
     */
    private function seedCompanyWithListings(int $count, ?string $name = null): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('listing-tags@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Listing Tags Co')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);

        $listings = [];
        for ($i = 1; $i <= $count; ++$i) {
            $listing = MarketplaceListingBuilder::aListing()
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::WILDBERRIES)
                ->withMarketplaceSku('wb-tag-sku-'.$i)
                ->withName(null === $name ? null : $name.' '.$i)
                ->build();
            $em->persist($listing);
            $listings[] = $listing;
        }

        $em->flush();

        return [$owner, $company, $listings];
    }

    private function seedForeignCompanyWithListing(?string $name = null): string
    {
        $owner = UserBuilder::aUser()
            ->withIndex(902)
            ->withEmail('listing-tags-foreign@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::OTHER_COMPANY_ID)
            ->withOwner($owner)
            ->withName('Foreign Co')
            ->build();

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-foreign-1')
            ->withName($name)
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($listing);
        $em->flush();

        return $listing->getId();
    }

    private function seedTag(string $companyId, string $name): string
    {
        $tag = MarketplaceListingTagBuilder::aTag()
            ->forCompanyId($companyId)
            ->withName($name)
            ->build();

        $this->em()->persist($tag);
        $this->em()->flush();

        return $tag->getId();
    }

    private function seedAssignment(string $companyId, string $listingId, string $tagId): void
    {
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
             VALUES (?, ?, ?, NOW())',
            [$listingId, $tagId, $companyId],
        );
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(KernelBrowser $client, string $url, array $body): void
    {
        $client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return $decoded;
    }

    /**
     * @param list<string> $params
     */
    private function fetchOne(string $sql, array $params): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, $params);
    }
}
