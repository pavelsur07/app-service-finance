<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * Фильтр «История raw-документов» не должен сбрасывать выбранный месяц карты покрытия.
 */
final class MarketplaceIndexHistoryFilterMonthTest extends WebTestCaseBase
{
    public function testHistoryFilterFormKeepsSelectedMonth(): void
    {
        $client = static::createClient();

        $owner = UserBuilder::aUser()->withEmail('history-month@example.test')->withRoles(['ROLE_COMPANY_OWNER'])->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();
        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/marketplace?month=2026-08');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#sync-history-marketplace')->closest('form');
        self::assertNotNull($form);
        self::assertSame('2026-08', $form->filter('input[type=hidden][name=month]')->attr('value'));

        $crawler = $client->request('GET', '/marketplace');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#sync-history-marketplace')->closest('form');
        self::assertNotNull($form);
        self::assertCount(0, $form->filter('input[name=month]'));

        // Хуки assets/pages/marketplace_index.js: модалы без inline-скрипта, адрес API в data-атрибуте.
        self::assertSame('/api/marketplace/bulk-process', $crawler->filter('#bulkProcessModal')->attr('data-bulk-process-url'));
        self::assertCount(1, $crawler->filter('#bulkProcessModal [data-bulk-marketplace] option[value="ozon"]'));
        self::assertCount(1, $crawler->filter('#bulkProcessModal input[type="month"][data-bulk-month]'));
        self::assertCount(1, $crawler->filter('#bulkProcessModal button[data-bulk-process-submit]'));
        self::assertCount(1, $crawler->filter('#modal-apply-realization[data-apply-realization-modal] form[data-apply-realization-form] input[name="_token"][data-apply-realization-token]'));
    }
}
