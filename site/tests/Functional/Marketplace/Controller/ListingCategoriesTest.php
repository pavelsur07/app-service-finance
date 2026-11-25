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
use App\Tests\Builders\Marketplace\MarketplaceListingCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ListingCategoriesTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000b01';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000b02';
    private const MANAGE_URL = '/marketplace/listings/categories';
    private const ASSIGN_URL = '/api/marketplace/listings/categories/assign';
    private const TOKEN_ID = 'listing_categories';

    public function testCreateRenameAndDuplicate(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany(0);
        $this->login($client, $owner, $company);
        $token = $this->csrfToken($client, self::TOKEN_ID, self::MANAGE_URL);

        $client->request('POST', self::MANAGE_URL.'/create', ['name' => ' Платья ', '_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        $id = (string) $this->fetchOne('SELECT id FROM marketplace_listing_categories WHERE company_id = ?', [self::COMPANY_ID]);
        self::assertTrue(Uuid::isValid($id));

        $client->request('POST', self::MANAGE_URL.'/create', ['name' => 'ПЛАТЬЯ', '_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        self::assertSame(1, $this->categoryCount(self::COMPANY_ID));

        $client->request('POST', self::MANAGE_URL.'/create', ['name' => '   ', '_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        self::assertSame(1, $this->categoryCount(self::COMPANY_ID));

        $client->request('POST', self::MANAGE_URL.'/'.$id.'/rename', ['name' => 'Платья и сарафаны', '_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        self::assertSame('Платья и сарафаны', $this->fetchOne('SELECT name FROM marketplace_listing_categories WHERE id = ?', [$id]));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="listing-categories"] input[value="Платья и сарафаны"]');
    }

    public function testInvalidCsrfChangesNothing(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany(0);
        $this->login($client, $owner, $company);

        $client->request('POST', self::MANAGE_URL.'/create', ['name' => 'Платья', '_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->categoryCount(self::COMPANY_ID));
    }

    public function testForeignCategoryIsNotFoundForRenameAndDelete(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany(0);
        $this->seedForeignCompany();
        $foreign = $this->seedCategory(self::OTHER_COMPANY_ID, 'Чужая');
        $this->login($client, $owner, $company);
        $token = $this->csrfToken($client, self::TOKEN_ID, self::MANAGE_URL);

        $client->request('POST', self::MANAGE_URL.'/'.$foreign.'/rename', ['name' => 'Моя', '_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', self::MANAGE_URL.'/'.$foreign.'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('Чужая', $this->fetchOne('SELECT name FROM marketplace_listing_categories WHERE id = ?', [$foreign]));
    }

    public function testDeleteRefusesUsedCategory(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompany(1);
        $used = $this->seedCategory(self::COMPANY_ID, 'Платья');
        $unused = $this->seedCategory(self::COMPANY_ID, 'Юбки');
        $this->login($client, $owner, $company);
        $this->postJson($client, ['listingIds' => [$listings[0]->getId()], 'categoryId' => $used]);
        $token = $this->csrfToken($client, self::TOKEN_ID, self::MANAGE_URL);

        $client->request('POST', self::MANAGE_URL.'/'.$used.'/delete', ['_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        $client->request('POST', self::MANAGE_URL.'/'.$unused.'/delete', ['_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);

        self::assertSame(['Платья'], $this->em()->getConnection()->fetchFirstColumn(
            'SELECT name FROM marketplace_listing_categories WHERE company_id = ?',
            [self::COMPANY_ID],
        ));

        $client->request('GET', self::MANAGE_URL);
        self::assertSelectorExists('[data-confirm-blocked="true"]');
    }

    public function testAssignByIdsByFilterAndUnassign(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompany(3);
        $category = $this->seedCategory(self::COMPANY_ID, 'Платья');
        $this->login($client, $owner, $company);
        $ids = array_map(static fn (MarketplaceListing $l): string => $l->getId(), $listings);

        $this->postJson($client, ['listingIds' => [$ids[0]], 'categoryId' => $category]);
        self::assertResponseIsSuccessful();
        self::assertSame(['matched' => 1, 'changed' => 1, 'categoryName' => 'Платья'], $this->json($client));

        $this->postJson($client, ['filter' => ['q' => 'листинг'], 'categoryId' => $category]);
        self::assertSame(['matched' => 3, 'changed' => 2, 'categoryName' => 'Платья'], $this->json($client));

        $this->postJson($client, ['filter' => ['category' => $category], 'categoryId' => $category]);
        self::assertSame(0, $this->json($client)['changed']);

        $this->postJson($client, ['listingIds' => [$ids[1]], 'categoryId' => null]);
        self::assertSame(['matched' => 1, 'changed' => 1, 'categoryName' => null], $this->json($client));

        $this->postJson($client, ['filter' => ['category' => 'none'], 'categoryId' => $category]);
        self::assertSame(1, $this->json($client)['changed']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testAssignRejectsInvalidPayload(array $payload, int $status, string $code): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompany(1);
        $this->login($client, $owner, $company);
        $payload = json_decode(str_replace('@LISTING@', $listings[0]->getId(), (string) json_encode($payload)), true);

        $this->postJson($client, $payload);

        self::assertResponseStatusCodeSame($status);
        self::assertSame($code, $this->json($client)['error']['code']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'нет categoryId' => [['listingIds' => ['@LISTING@']], 422, 'category_id_required'];
        yield 'categoryId не uuid' => [['listingIds' => ['@LISTING@'], 'categoryId' => 'x'], 422, 'category_id_invalid'];
        yield 'и ids, и фильтр' => [['listingIds' => ['@LISTING@'], 'filter' => [], 'categoryId' => null], 422, 'listing_selection_required'];
        yield 'пустой выбор' => [['listingIds' => [], 'categoryId' => null], 422, 'listing_ids_required'];
        yield 'кривой фильтр' => [['filter' => ['category' => 'x'], 'categoryId' => null], 422, 'filter_invalid'];
        yield 'чужая категория' => [['listingIds' => ['@LISTING@'], 'categoryId' => '01900000-0000-7000-8000-000000000000'], 404, 'category_not_found'];
    }

    public function testReadOnlyMemberCannotAssignOrCreate(): void
    {
        $client = static::createClient();
        [, $company, $listings] = $this->seedCompany(1);
        $category = $this->seedCategory(self::COMPANY_ID, 'Платья');
        $member = $this->seedReadOnlyMember($company);
        $this->login($client, $member, $company);

        $this->postJson($client, ['listingIds' => [$listings[0]->getId()], 'categoryId' => $category]);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::MANAGE_URL.'/create', ['name' => 'Юбки', '_token' => $this->csrfToken($client, self::TOKEN_ID, self::MANAGE_URL)]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(1, $this->categoryCount(self::COMPANY_ID));
        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listings WHERE category_id IS NOT NULL', []));
    }

    public function testRegistryShowsColumnAndFiltersByCategory(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompany(2);
        $category = $this->seedCategory(self::COMPANY_ID, 'Платья');
        $this->login($client, $owner, $company);
        $this->postJson($client, ['listingIds' => [$listings[0]->getId()], 'categoryId' => $category]);

        $crawler = $client->request('GET', '/marketplace/listings?category='.$category);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="listings-found"]', 'Найдено: 1');
        self::assertSelectorTextContains('[data-testid="listings-found"]', 'категория «Платья»');
        self::assertSame('Платья', trim($crawler->filter('td[data-listing-category]')->text()));

        $client->request('GET', '/marketplace/listings?category=none');
        self::assertSelectorTextContains('[data-testid="listings-found"]', 'Найдено: 1');
        self::assertSelectorTextContains('[data-testid="listings-found"]', 'без категории');
    }

    public function testRuleFromUnmatchedKeyAssignsListings(): void
    {
        $client = static::createClient();
        [$owner, $company, $listings] = $this->seedCompany(3);
        $listings[0]->setMarketplaceData(['category_key' => 'wb:69', 'subject_name' => 'Платья']);
        $listings[1]->setMarketplaceData(['category_key' => 'wb:69', 'subject_name' => 'Платья']);
        $listings[2]->setMarketplaceData(['category_key' => 'ozon:1:2']);
        $this->em()->flush();
        $dresses = $this->seedCategory(self::COMPANY_ID, 'Платья');
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::MANAGE_URL);
        self::assertSame(['wb:69', 'ozon:1:2'], $crawler->filter('[data-unmatched-key]')->each(static fn ($r): string => (string) $r->attr('data-unmatched-key')));
        self::assertSelectorTextContains('[data-unmatched-key="wb:69"]', 'Платья');

        $token = $this->csrfToken($client, self::TOKEN_ID, self::MANAGE_URL);
        $client->request('POST', self::MANAGE_URL.'/rules', ['sourceKey' => 'wb:69', 'categoryId' => $dresses, '_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        self::assertSame(2, (int) $this->fetchOne("SELECT COUNT(*) FROM marketplace_listings WHERE category_id = ? AND category_source = 'rule'", [$dresses]));

        $crawler = $client->followRedirect();
        self::assertSame(['ozon:1:2'], $crawler->filter('[data-unmatched-key]')->each(static fn ($r): string => (string) $r->attr('data-unmatched-key')));
        self::assertSelectorTextContains('[data-rule-key="wb:69"]', '2');
    }

    public function testRuleRejectsForeignCategoryBadKeyAndCsrf(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany(0);
        $this->seedForeignCompany();
        $foreign = $this->seedCategory(self::OTHER_COMPANY_ID, 'Чужая');
        $mine = $this->seedCategory(self::COMPANY_ID, 'Платья');
        $this->login($client, $owner, $company);
        $token = $this->csrfToken($client, self::TOKEN_ID, self::MANAGE_URL);

        $client->request('POST', self::MANAGE_URL.'/rules', ['sourceKey' => 'wb:69', 'categoryId' => $foreign, '_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', self::MANAGE_URL.'/rules', ['sourceKey' => 'wb:abc', 'categoryId' => $mine, '_token' => $token]);
        self::assertResponseRedirects(self::MANAGE_URL);
        $client->request('POST', self::MANAGE_URL.'/rules', ['sourceKey' => 'wb:69', 'categoryId' => $mine, '_token' => 'bad']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_category_rules', []));
    }

    /**
     * @return array{User, Company, list<MarketplaceListing>}
     */
    private function seedCompany(int $listings): array
    {
        $owner = UserBuilder::aUser()->withEmail('listing-categories@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->withName('Categories Co')->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);

        $result = [];
        for ($i = 1; $i <= $listings; ++$i) {
            $listing = MarketplaceListingBuilder::aListing()
                ->forCompany($company)
                ->withMarketplace(0 === $i % 2 ? MarketplaceType::WILDBERRIES : MarketplaceType::OZON)
                ->withMarketplaceSku('cat-sku-'.$i)
                ->withName('Листинг '.$i)
                ->build();
            $em->persist($listing);
            $result[] = $listing;
        }
        $em->flush();

        return [$owner, $company, $result];
    }

    private function seedForeignCompany(): void
    {
        $owner = UserBuilder::aUser()->withIndex(903)->withEmail('listing-categories-foreign@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($owner)->withName('Foreign Co')->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();
    }

    private function seedCategory(string $companyId, string $name): string
    {
        $category = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId($companyId)->withName($name)->build();
        $this->em()->persist($category);
        $this->em()->flush();

        return $category->getId();
    }

    private function seedReadOnlyMember(Company $company): User
    {
        $role = new CompanyRole(
            Uuid::uuid4()->toString(),
            'Только чтение маркетплейса',
            [Module::MARKETPLACE->value => AccessLevel::READ->value],
            $company,
        );
        $member = UserBuilder::aUser()
            ->withIndex(80)
            ->withEmail('listing-categories-readonly@example.test')
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

        return $member;
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(KernelBrowser $client, array $body): void
    {
        $client->request('POST', self::ASSIGN_URL, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return $decoded;
    }

    private function categoryCount(string $companyId): int
    {
        return (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_categories WHERE company_id = ?', [$companyId]);
    }

    /**
     * @param list<string> $params
     */
    private function fetchOne(string $sql, array $params): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, $params);
    }
}
