<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\ProjectDirection;
use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

final class PlReportPreviewControllerTest extends WebTestCaseBase
{
    public function testPreviewOpensAndSeedsPlStructure(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview@example.test');

        $client->request('GET', '/finance/report/preview');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $this->em()->getRepository(PLCategory::class)->count(['company' => $company]),
        );
    }

    /**
     * Ширины колонок заданы через colgroup, поэтому рассинхрон colgroup и шапки ломает раскладку
     * молча: таблица просто перестаёт держать ширины. Проверяем оба layout и обе меты.
     */
    public function testColgroupMatchesHeaderColumns(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-colgroup@example.test');

        foreach ([
            '/finance/report/preview',
            '/finance/report/preview?show_meta=1',
            '/finance/report/preview?layout=projects',
            '/finance/report/preview?layout=projects&show_meta=1',
        ] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);

            $cols = $crawler->filter('table.pl-table colgroup col')->count();
            $headers = $crawler->filter('table.pl-table thead th')->count();

            self::assertGreaterThan(0, $cols, $url);
            self::assertSame($headers, $cols, $url);
        }
    }

    /**
     * Согласованные ширины: день/месяц 160px, неделя 220px, проект 200px, итого 180px.
     */
    public function testGroupingUsesReadableDataColumnWidths(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-week@example.test');

        $dayStyle = $this->tableStyle($client, '/finance/report/preview?grouping=day');
        $weekStyle = $this->tableStyle($client, '/finance/report/preview?grouping=week');
        $monthStyle = $this->tableStyle($client, '/finance/report/preview?grouping=month');

        self::assertStringContainsString('--pl-col-period: 160px', $dayStyle);
        self::assertStringContainsString('--pl-col-period: 220px', $weekStyle);
        self::assertStringContainsString('--pl-col-period: 160px', $monthStyle);
        self::assertStringContainsString('min-width: calc(', $monthStyle);

        $client->request('GET', '/finance/report/preview?layout=projects');
        self::assertResponseIsSuccessful();

        $html = $client->getResponse()->getContent();
        self::assertIsString($html);
        self::assertStringContainsString('--pl-col-project: 200px', $html);
        self::assertStringContainsString('--pl-col-total: 180px', $html);
    }

    public function testNumericCellsExposeFullValueInTitle(): void
    {
        $client = static::createClient();
        $company = $this->loginWithCompany($client, 'pl-report-preview-title@example.test');
        $this->em()->persist(new ProjectDirection(
            '33333333-3333-3333-3333-000000000901',
            $company,
            'Тестовый проект',
        ));
        $this->em()->flush();

        foreach ([
            '/finance/report/preview',
            '/finance/report/preview?layout=projects',
        ] as $url) {
            $crawler = $client->request('GET', $url);

            self::assertResponseIsSuccessful($url);

            $cells = $crawler->filter('table.pl-table tbody tr.pl-row td.text-end');
            self::assertGreaterThan(0, $cells->count(), $url);

            $cells->each(static function (Crawler $cell) use ($url): void {
                self::assertSame(trim($cell->text()), $cell->attr('title'), $url);
            });
        }
    }

    private function tableStyle(KernelBrowser $client, string $url): string
    {
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful($url);

        return (string) $crawler->filter('table.pl-table')->attr('style');
    }

    private function loginWithCompany(KernelBrowser $client, string $email): object
    {
        $this->resetDb();

        $user = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        return $company;
    }
}
