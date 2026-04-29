<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Enum\PLCategoryType;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CostPLMappingDefaultSetupControllerTest extends WebTestCaseBase
{
    public function testPreviewReturnsOkAndIsReadOnly(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $before = $this->countMappings();
        $client->request('POST', '/marketplace/cost-pl-mapping/default/preview', [
            'marketplace' => 'ozon',
            '_token' => $this->csrf($client),
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertArrayHasKey('summary', $payload);
        self::assertSame($before, $this->countMappings());
    }

    public function testApplyIsIdempotentAndUsesActiveCompany(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/cost-pl-mapping/default/apply', [
            'marketplace' => 'ozon',
            'companyId' => Uuid::uuid4()->toString(),
            '_token' => $this->csrf($client),
        ]);
        self::assertResponseIsSuccessful();
        $first = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($first['ok']);

        $createdAfterFirst = $this->countMappings();
        self::assertGreaterThan(0, $createdAfterFirst);

        $client->request('POST', '/marketplace/cost-pl-mapping/default/apply', [
            'marketplace' => 'ozon',
            '_token' => $this->csrf($client),
        ]);
        self::assertResponseIsSuccessful();
        $second = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($second['ok']);
        self::assertSame($createdAfterFirst, $this->countMappings());
    }

    public function testApplyReturnsErrorForBlockingIssuesAndInvalidMarketplace(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData(false);
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/cost-pl-mapping/default/apply', [
            'marketplace' => 'ozon',
            '_token' => $this->csrf($client),
        ]);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);

        $client->request('POST', '/marketplace/cost-pl-mapping/default/preview', [
            'marketplace' => 'unknown',
            '_token' => $this->csrf($client),
        ]);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
    }

    public function testInvalidCsrfReturnsError(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('POST', '/marketplace/cost-pl-mapping/default/apply', [
            'marketplace' => 'ozon',
            '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    private function csrf(KernelBrowser $client): string
    {
        return $client->getContainer()->get('security.csrf.token_manager')->getToken('marketplace_default_cost_mapping')->getValue();
    }

    private function countMappings(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM marketplace_cost_pl_mappings');
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }

    private function seedBaseData(bool $withAllPlCodes = true): array
    {
        $user = UserBuilder::aUser()->withEmail('default-mapping@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $em = $this->em();
        $em->persist($user);
        $em->persist($company);

        $codes = ['ozon_sale_commission', 'ozon_logistic_direct'];
        foreach ($codes as $i => $code) {
            $cost = new MarketplaceCostCategory(Uuid::uuid4()->toString(), $company, MarketplaceType::OZON);
            $cost->setName('Cost '.$i)->setCode($code);
            $em->persist($cost);
        }

        $plCodes = $withAllPlCodes ? ['COGS_MP_COMMISSION', 'COGS_DELIVERY'] : ['COGS_MP_COMMISSION'];
        foreach ($plCodes as $code) {
            $pl = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName($code)->build();
            $pl->setCode($code);
            $pl->setType(PLCategoryType::LEAF_INPUT);
            $em->persist($pl);
        }

        $em->flush();

        return [$user, $company];
    }
}
