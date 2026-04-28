<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Entity\MarketplaceMonthClose;
use App\Marketplace\Enum\CloseStage;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class MonthCloseHistoryControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-c00000000001';
    private const OWNER_ID   = '22222222-2222-2222-2222-c00000000001';

    public function testHistoryKeepsFullyPreliminaryFinalAndMixedPeriodsVisible(): void
    {
        $client = static::createClient();
        $this->resetDb();
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
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();
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
                if ($link->count() === 0) {
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
