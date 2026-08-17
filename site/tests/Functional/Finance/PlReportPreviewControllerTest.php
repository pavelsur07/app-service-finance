<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

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
     * Ярлык недели («Неделя 22 (25.05.2026 — 31.05.2026)») вдвое длиннее месячного и в 120px
     * не помещается.
     */
    public function testWeekGroupingWidensPeriodColumns(): void
    {
        $client = static::createClient();
        $this->loginWithCompany($client, 'pl-report-preview-week@example.test');

        $weekStyle = $this->tableStyle($client, '/finance/report/preview?grouping=week');
        $monthStyle = $this->tableStyle($client, '/finance/report/preview?grouping=month');

        self::assertStringContainsString('--pl-col-period: 210px', $weekStyle);
        self::assertStringContainsString('--pl-col-period: 120px', $monthStyle);
        self::assertStringContainsString('min-width: calc(', $monthStyle);
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
