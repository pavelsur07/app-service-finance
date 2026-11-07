<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

final class MonthCloseHistoryControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-c00000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-c00000000001';

    public function testHistoryKeepsFullyPreliminaryFinalAndMixedPeriodsVisible(): void
    {
        $client = static::createClient();
        $this->seedActiveSession($client);

        $now = new \DateTimeImmutable('now');
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');

        $this->persistMonthClose(
            id: '33333333-3333-3333-3333-c00000000001',
            year: $currentYear,
            month: $currentMonth,
            salesClosed: true,
            costsClosed: true,
            salesPreliminary: true,
            costsPreliminary: true,
        );

        $historyYear = $currentYear - 1;

        $this->persistMonthClose(
            id: '33333333-3333-3333-3333-c00000000002',
            year: $historyYear,
            month: 2,
            salesClosed: true,
            costsClosed: true,
            salesPreliminary: false,
            costsPreliminary: false,
        );

        $this->persistMonthClose(
            id: '33333333-3333-3333-3333-c00000000003',
            year: $historyYear,
            month: 3,
            salesClosed: true,
            costsClosed: true,
            salesPreliminary: true,
            costsPreliminary: false,
        );

        $this->persistMonthClose(
            id: '33333333-3333-3333-3333-c00000000004',
            year: $historyYear,
            month: 4,
            salesClosed: true,
            costsClosed: false,
            salesPreliminary: true,
            costsPreliminary: false,
        );

        $client->request('GET', sprintf(
            '/marketplace/month-close?marketplace=ozon&year=%d&month=%d',
            $currentYear,
            $currentMonth,
        ));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $crawler = new Crawler((string) $client->getResponse()->getContent());

        $this->assertHistoryRowContains(
            crawler: $crawler,
            year: $currentYear,
            month: $currentMonth,
            fragments: ['Оперативное закрытие'],
        );

        $this->assertHistoryRowContains(
            crawler: $crawler,
            year: $historyYear,
            month: 2,
            fragments: ['Закрыт'],
        );

        $this->assertHistoryRowContains(
            crawler: $crawler,
            year: $historyYear,
            month: 3,
            fragments: ['Оперативное закрытие', 'Закрыт'],
        );

        $this->assertHistoryRowContains(
            crawler: $crawler,
            year: $historyYear,
            month: 4,
            fragments: ['Оперативное закрытие', 'Не закрыт'],
        );
    }

    public function testReopenedStageOfPastMonthTellsThatOperationalPlIsMissing(): void
    {
        $client = static::createClient();
        $this->seedActiveSession($client);

        $year = (int) (new \DateTimeImmutable('now'))->format('Y') - 1;
        $this->persistReopenedCosts('33333333-3333-3333-3333-c00000000011', $year, 5);

        $client->request('GET', sprintf('/marketplace/month-close?marketplace=ozon&year=%d&month=5', $year));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $crawler = new Crawler((string) $client->getResponse()->getContent());
        $notice = $crawler->filter('[data-role="reopened-notice"]');
        self::assertCount(1, $notice, 'Предупреждение только у переоткрытого этапа затрат.');
        self::assertStringContainsString('Оперативного ОПиУ по этапу нет', $notice->text());
        self::assertStringContainsString('закройте этап вручную', $notice->text());
        self::assertStringNotContainsString('Ежедневный пересчёт', $notice->text());

        $historyBadge = $crawler->filter('table tbody tr span.badge[title]');
        self::assertCount(1, $historyBadge);
        self::assertSame('Переоткрыт', trim($historyBadge->text()));
        self::assertStringContainsString('оперативного ОПиУ нет', (string) $historyBadge->attr('title'));
    }

    public function testReopenedStageOfCurrentMonthMentionsDailyRebuild(): void
    {
        $client = static::createClient();
        $this->seedActiveSession($client);

        $now = new \DateTimeImmutable('now');
        $this->persistReopenedCosts('33333333-3333-3333-3333-c00000000012', (int) $now->format('Y'), (int) $now->format('n'));

        $client->request('GET', sprintf(
            '/marketplace/month-close?marketplace=ozon&year=%d&month=%d',
            (int) $now->format('Y'),
            (int) $now->format('n'),
        ));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $notice = (new Crawler((string) $client->getResponse()->getContent()))->filter('[data-role="reopened-notice"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Ежедневный пересчёт', $notice->text());
        self::assertStringNotContainsString('закройте этап вручную', $notice->text());
    }

    /**
     * Модуль assets/pages/month_close.js: адреса API на корне, preflight и закрытие по data-атрибутам,
     * «Переоткрыть» — через общий диалог подтверждения с полями этапа в data-confirm-fields.
     */
    public function testStageCardsExposeModuleHooksAndReopenGoesThroughConfirmDialog(): void
    {
        $client = static::createClient();
        $this->seedActiveSession($client);

        $year = (int) (new \DateTimeImmutable('now'))->format('Y') - 1;
        $this->persistMonthClose(
            id: '33333333-3333-3333-3333-c00000000021',
            year: $year,
            month: 6,
            salesClosed: true,
            costsClosed: false,
            salesPreliminary: false,
            costsPreliminary: false,
        );

        $crawler = $client->request('GET', sprintf('/marketplace/month-close?marketplace=ozon&year=%d&month=6', $year));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $root = $crawler->filter('[data-month-close]');
        self::assertCount(1, $root);
        self::assertSame('/marketplace/month-close/preflight', $root->attr('data-preflight-url'));
        self::assertSame('/marketplace/month-close/preliminary/rebuild', $root->attr('data-rebuild-url'));
        self::assertCount(1, $crawler->filter('form[data-autosubmit] select[name="month"]'));

        $preflight = $crawler->filter('button[data-preflight][data-stage="costs"]');
        self::assertCount(1, $preflight);
        self::assertSame('ozon', $preflight->attr('data-marketplace'));
        self::assertSame('6', $preflight->attr('data-month'));
        self::assertNotSame('', (string) $preflight->attr('data-token'));
        self::assertCount(1, $crawler->filter('[data-preflight-result="costs"][hidden]'));
        self::assertCount(1, $crawler->filter('form[data-busy-submit] button[data-close-stage="costs"][disabled]'));
        self::assertCount(0, $crawler->filter('[onclick], [onsubmit], [onchange]'));

        $trigger = $crawler->filter('[data-confirm-action$="/marketplace/month-close/reopen-stage"]');
        self::assertCount(1, $trigger, 'Переоткрыть можно только закрытый этап продаж.');
        self::assertSame('#month-close-confirm', $trigger->attr('data-bs-target'));
        self::assertSame(
            ['marketplace' => 'ozon', 'year' => $year, 'month' => 6, 'stage' => 'sales_returns'],
            json_decode((string) $trigger->attr('data-confirm-fields'), true, 512, \JSON_THROW_ON_ERROR),
        );
        self::assertCount(1, $crawler->filter('#month-close-confirm[data-confirm-modal]'));

        $this->submitConfirmTrigger($client, $trigger);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $crawler = new Crawler((string) $client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-confirm-action$="/marketplace/month-close/reopen-stage"]'));
        self::assertCount(1, $crawler->filter('button[data-preflight][data-stage="sales_returns"]'));
        self::assertCount(1, $crawler->filter('[data-role="reopened-notice"]'));
    }

    private function persistReopenedCosts(string $id, int $year, int $month): void
    {
        $monthClose = new MarketplaceMonthClose(
            id: $id,
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            year: $year,
            month: $month,
        );
        $monthClose->closeStage(CloseStage::COSTS, self::OWNER_ID, [], []);
        $monthClose->reopenStage(CloseStage::COSTS);

        $this->em()->persist($monthClose);
        $this->em()->flush();
    }

    private function seedActiveSession(KernelBrowser $client): void
    {
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('month-close-history-owner@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
    }

    private function persistMonthClose(
        string $id,
        int $year,
        int $month,
        bool $salesClosed,
        bool $costsClosed,
        bool $salesPreliminary,
        bool $costsPreliminary,
    ): void {
        $monthClose = new MarketplaceMonthClose(
            id: $id,
            companyId: self::COMPANY_ID,
            marketplace: MarketplaceType::OZON,
            year: $year,
            month: $month,
        );

        if ($salesClosed) {
            $monthClose->closeStage(CloseStage::SALES_RETURNS, self::OWNER_ID, [], []);
        }

        if ($costsClosed) {
            $monthClose->closeStage(CloseStage::COSTS, self::OWNER_ID, [], []);
        }

        $monthClose->setSettings([
            'last_close_was_preliminary' => [
                CloseStage::SALES_RETURNS->value => $salesPreliminary,
                CloseStage::COSTS->value => $costsPreliminary,
            ],
        ]);

        $this->em()->persist($monthClose);
        $this->em()->flush();
    }

    /**
     * @param list<string> $fragments
     */
    private function assertHistoryRowContains(Crawler $crawler, int $year, int $month, array $fragments): void
    {
        $rows = $crawler->filter('table tbody tr')->reduce(
            static function (Crawler $row) use ($year, $month): bool {
                $link = $row->filter('a[href*="marketplace/month-close"]');
                if (0 === $link->count()) {
                    return false;
                }

                $href = (string) $link->attr('href');

                return str_contains($href, sprintf('year=%d', $year))
                    && str_contains($href, sprintf('month=%d', $month));
            },
        );

        self::assertSame(1, $rows->count(), sprintf('Период %d-%02d должен присутствовать в истории.', $year, $month));

        $rowText = $rows->text();
        foreach ($fragments as $fragment) {
            self::assertStringContainsString($fragment, $rowText);
        }
    }
}
