<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Entity\PLCategory;
use App\Marketplace\Controller\CostCategoryDeleteController;
use App\Marketplace\Controller\CostPLMappingUpdateController;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class CostPLMappingControllerTest extends WebTestCaseBase
{
    public function testIndexRendersDefaultMappingUiElements(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        $this->em()->flush();
        $this->login($client, $user, $company);

        $client->request('GET', '/marketplace/cost-pl-mapping');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Настроить базовый маппинг', $html);
        self::assertSelectorExists('#modal-default-cost-mapping [data-default-mapping-modal]');
        self::assertSelectorExists('#modal-default-cost-mapping [data-default-mapping-apply]');

        self::assertSelectorExists('[data-preview-url="/marketplace/cost-pl-mapping/default/preview"]');
        self::assertSelectorExists('[data-apply-url="/marketplace/cost-pl-mapping/default/apply"]');

        self::assertSelectorExists('[data-default-mapping-modal][data-csrf-token]:not([data-csrf-token=""])');
    }

    public function testIndexRendersReadOnlyRowsWithSingleSelect(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        $pl = $this->persistPl($company, 'Логистика');
        $this->persistCost($company, 'Доставка');
        $this->persistCost($company, 'Хранение');
        $this->em()->flush();
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/marketplace/cost-pl-mapping?marketplace=ozon');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('tr[data-mapping-row]'));
        self::assertCount(0, $crawler->filter('tr[data-mapping-row] select'), 'Select статей ОПиУ в каждой строке вернулся.');
        self::assertCount(1, $crawler->filter('#edit-mapping-pl'));
        self::assertCount(1, $crawler->filter(sprintf('#edit-mapping-pl option[value="%s"]', $pl->getId())));
    }

    public function testIndexQueryCountDoesNotGrowWithPlTree(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        $root = $this->persistPl($company, 'Root');
        $this->persistCost($company, 'Доставка');
        $this->em()->flush();

        // Первый запрос прогревает сессию и кэши — считаем со второго.
        $this->countIndexQueries($client, $user, $company);
        $small = $this->countIndexQueries($client, $user, $company);

        // Запросы клиента пересоздают EntityManager — берём сущности из текущего.
        $company = $this->em()->find(Company::class, $company->getId());
        $root = $this->em()->find(PLCategory::class, $root->getId());
        self::assertInstanceOf(Company::class, $company);
        self::assertInstanceOf(PLCategory::class, $root);

        for ($i = 0; $i < 10; ++$i) {
            $child = $this->persistPl($company, 'Child '.$i, $root);
            $this->persistPl($company, 'Leaf '.$i, $child);
            $this->persistCost($company, 'Cost '.$i);
        }
        $this->em()->flush();

        self::assertSame($small, $this->countIndexQueries($client, $user, $company));
    }

    public function testUpdateSavesRowAndReturnsJson(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        $pl = $this->persistPl($company, 'Логистика');
        $cost = $this->persistCost($company, 'Доставка');
        $this->em()->flush();
        $this->login($client, $user, $company);

        $this->postUpdate($client, (string) $cost->getId(), ['plCategoryId' => $pl->getId(), 'includeInPl' => false, 'sortOrder' => 40]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['costCategoryId' => $cost->getId(), 'plCategoryId' => $pl->getId(), 'includeInPl' => false, 'sortOrder' => 40],
            $this->json($client),
        );
    }

    public function testUpdateOfForeignCostCategoryIsNotFound(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        [, $foreign] = $this->seedCompany(2);
        $foreignCost = $this->persistCost($foreign, 'Чужая');
        $this->em()->flush();
        $this->login($client, $user, $company);

        $this->postUpdate($client, (string) $foreignCost->getId(), ['plCategoryId' => null, 'includeInPl' => true, 'sortOrder' => 0]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('cost_category_not_found', $this->json($client)['error']['code']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings'));
    }

    public function testUpdateRejectsForeignPlCategoryInvalidSortOrderAndCsrf(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        [, $foreign] = $this->seedCompany(2);
        $foreignPl = $this->persistPl($foreign, 'Чужая статья');
        $cost = $this->persistCost($company, 'Доставка');
        $this->em()->flush();
        $this->login($client, $user, $company);

        $this->postUpdate($client, (string) $cost->getId(), ['plCategoryId' => $foreignPl->getId(), 'includeInPl' => true, 'sortOrder' => 0]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('pl_category_invalid', $this->json($client)['error']['code']);

        $this->postUpdate($client, (string) $cost->getId(), ['plCategoryId' => null, 'includeInPl' => true, 'sortOrder' => 40000]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('sort_order_invalid', $this->json($client)['error']['code']);

        $this->postUpdate($client, (string) $cost->getId(), ['plCategoryId' => null, 'includeInPl' => true, 'sortOrder' => 0], 'invalid');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings'));
    }

    public function testDeleteWithSharedTokenSoftDeletesCategory(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedCompany(1);
        $cost = $this->persistCost($company, 'Пользовательская');
        $this->em()->flush();
        $this->login($client, $user, $company);

        $client->request('POST', sprintf('/marketplace/cost-pl-mapping/%s/delete-category', $cost->getId()), [
            '_token' => $this->csrfToken($client, CostCategoryDeleteController::CSRF_TOKEN_ID),
            'marketplace' => 'ozon',
        ]);

        self::assertResponseRedirects('/marketplace/cost-pl-mapping?marketplace=ozon');
        self::assertNotNull($this->em()->getConnection()->fetchOne(
            'SELECT deleted_at FROM marketplace_cost_categories WHERE id = :id',
            ['id' => $cost->getId()],
        ));
    }

    /** @param array<string, mixed> $payload */
    private function postUpdate(KernelBrowser $client, string $costCategoryId, array $payload, ?string $token = null): void
    {
        $client->request(
            'POST',
            sprintf('/marketplace/cost-pl-mapping/%s', $costCategoryId),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token ?? $this->csrfToken($client, CostPLMappingUpdateController::CSRF_TOKEN_ID),
            ],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function countIndexQueries(KernelBrowser $client, User $user, Company $company): int
    {
        $this->login($client, $user, $company);
        $client->enableProfiler();
        $client->request('GET', '/marketplace/cost-pl-mapping');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'Профайлер выключен — измерить число запросов нечем.');
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /** @return array{User, Company} */
    private function seedCompany(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);

        return [$user, $company];
    }

    private function persistPl(Company $company, string $name, ?PLCategory $parent = null): PLCategory
    {
        $pl = PLCategoryBuilder::aPLCategory()->withId(Uuid::uuid4()->toString())->forCompany($company)->withName($name)->withParent($parent)->build();
        $this->em()->persist($pl);

        return $pl;
    }

    private function persistCost(Company $company, string $name): MarketplaceCostCategory
    {
        $cost = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON);
        $cost->setName($name)->setCode('code_'.Uuid::uuid4()->toString());
        $this->em()->persist($cost);

        return $cost;
    }
}
