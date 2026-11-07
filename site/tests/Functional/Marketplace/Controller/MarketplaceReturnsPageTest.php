<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/** Smoke-тест страницы возвратов: рендер без данных, фильтр маркетплейса с автоотправкой. */
final class MarketplaceReturnsPageTest extends WebTestCaseBase
{
    public function testIndexRendersFilterAndRecalculateForm(): void
    {
        $client = static::createClient();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('returns-page-owner@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $crawler = $client->request('GET', '/marketplace/returns');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[data-autosubmit] select[name="marketplace"]'));
        self::assertCount(0, $crawler->filter('[onchange]'), 'inline-обработчиков на странице нет');
        self::assertSelectorExists('#modal-recalculate-cost form[data-busy-submit]');
    }
}
