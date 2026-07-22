<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Marketplace\Entity\MappingError;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class MarketplaceMappingErrorsPageTest extends WebTestCaseBase
{
    public function testEmptyUnresolvedListUsesEmptyState(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = UserBuilder::aUser()
            ->withEmail('admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();
        $this->em()->persist($admin);
        $this->em()->flush();

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/admin/marketplace/mapping-errors');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.empty'));
        self::assertSame('Все ошибки разобраны', $crawler->filter('.empty-title')->text());
        self::assertCount(0, $crawler->filter('.empty a'));
    }

    public function testUnresolvedErrorUsesUiKitComponentsAndDialog(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = UserBuilder::aUser()
            ->withEmail('admin@example.test')
            ->withRoles(['ROLE_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withName('ООО Ромашка')
            ->withOwner($admin)
            ->build();
        $error = new MappingError(
            id: '33333333-3333-4333-8333-333333333333',
            companyId: $company->getId(),
            marketplace: 'ozon',
            year: 2026,
            month: 7,
            serviceName: 'MarketplaceReturnStorageServiceAtThePickupPointFbsItem',
            operationType: 'services',
            totalAmount: 12345.67,
            rowsCount: 3,
            sampleRawJson: ['service_name' => 'MarketplaceReturnStorageServiceAtThePickupPointFbsItem'],
        );
        $fallbackError = new MappingError(
            id: '44444444-4444-4444-8444-444444444444',
            companyId: $company->getId(),
            marketplace: 'other',
            year: 2026,
            month: 7,
            serviceName: 'MarketplaceServiceFallback',
            operationType: 'services',
            totalAmount: -12345.67,
        );

        $em = $this->em();
        $em->persist($admin);
        $em->persist($company);
        $em->persist($error);
        $em->persist($fallbackError);
        $em->flush();

        $client->loginUser($admin, 'admin');
        $crawler = $client->request('GET', '/admin/marketplace/mapping-errors');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1.wz-title'));
        self::assertCount(1, $crawler->filter('table.t-table'));
        self::assertSame(
            ['Компания', 'Маркетплейс', 'Service name', 'Сумма', 'Строк', 'Обнаружено', 'Статус', 'Действия'],
            $crawler->filter('table.t-table thead th')->each(static fn ($cell): string => $cell->text()),
        );
        self::assertCount(8, $crawler->filter('table.t-table colgroup col'));
        self::assertCount(1, $crawler->filter('table.t-table[data-mapping-errors-table]'));
        self::assertCount(1, $crawler->filter('.mp-chip .mp-mark--ozon'));
        self::assertCount(2, $crawler->filter('.mp-chip'));
        self::assertCount(1, $crawler->filter('.mp-chip .mp-mark'));
        self::assertCount(2, $crawler->filter('table .status.status--danger'));

        $ozonRow = $crawler->filterXPath('//tr[td/code[text()="MarketplaceReturnStorageServiceAtThePickupPointFbsItem"]]');
        self::assertCount(8, $ozonRow->filter('td'));
        self::assertSame('ООО Ромашка', $ozonRow->filter('td')->eq(0)->text());
        self::assertCount(0, $crawler->filter('a[href^="mailto:"]'));
        self::assertStringContainsString("12\u{2009}345,67", $ozonRow->filter('.money')->text());

        $fallbackRow = $crawler->filterXPath('//tr[td/code[text()="MarketplaceServiceFallback"]]');
        self::assertCount(1, $fallbackRow);
        self::assertSame('OTHER', $fallbackRow->filter('.mp-chip')->text());
        self::assertStringContainsString("−12\u{2009}345,67", $fallbackRow->filter('.money')->text());

        $dialogId = 'sample-'.str_replace('-', '', $error->getId());
        self::assertCount(1, $crawler->filter(sprintf('[data-admin-dialog-open="%s"]', $dialogId)));
        $dialogTextarea = $crawler->filter(sprintf('dialog[id="%s"] textarea[readonly]', $dialogId));
        self::assertCount(1, $dialogTextarea);
        self::assertStringContainsString('"service_name": "MarketplaceReturnStorageServiceAtThePickupPointFbsItem"', $dialogTextarea->text());
        self::assertStringNotContainsString('\\"service_name\\"', $dialogTextarea->text());
        self::assertCount(1, $crawler->filter(sprintf('form[method="post"][action$="/%s/resolve"]', $error->getId())));
        self::assertCount(2, $crawler->filter('form [data-admin-confirm][data-admin-confirm-tone="primary"]'));

        $client->request('POST', sprintf('/admin/marketplace/mapping-errors/%s/resolve', $error->getId()));
        self::assertResponseRedirects('/admin/marketplace/mapping-errors');

        $crawler = $client->request('GET', '/admin/marketplace/mapping-errors?all=1');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table .status.status--success'));
        self::assertCount(1, $crawler->filter('form[action$="/resolve"]'));
        self::assertCount(0, $crawler->filter(sprintf('form[action$="/%s/resolve"]', $error->getId())));
        self::assertSame('Только нерешённые', $crawler->filter('.wz-head-actions a')->text());
    }
}
