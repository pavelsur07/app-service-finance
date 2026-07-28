<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class WbRawFinancialReportControllerTest extends WebTestCaseBase
{
    public function testRendersLoadedRawTotalsForActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(501);
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [[
            'reportId' => 9001,
            'rrdId' => 1001,
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'retailPriceWithDisc' => '2099',
            'retailAmount' => '1584',
            'forPay' => '1308.04',
            'acquiringFee' => '77.30',
        ]]);
        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', 'Контроль финансового отчёта WB');
        self::assertStringContainsString('1 308,04 RUB', $crawler->filter('body')->text());
        self::assertStringContainsString('9001', $crawler->filter('body')->text());
        self::assertStringContainsString('Транзакции системы не используются', $crawler->filter('body')->text());
    }

    public function testRendersDeductionBreakdownFromRawReasons(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(506);
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [
            [
                'reportId' => 9101,
                'rrdId' => 1101,
                'sellerOperName' => 'Удержание',
                'deduction' => '-7.50',
                'bonusTypeName' => 'Списание за отзыв',
            ],
            [
                'reportId' => 9102,
                'rrdId' => 1102,
                'sellerOperName' => 'Удержание',
                'deduction' => '2.50',
                'bonus_type_name' => 'Списание за отзыв',
            ],
            [
                'reportId' => 9102,
                'rrdId' => 1103,
                'sellerOperName' => 'Удержание',
                'deduction' => '-3',
            ],
        ]);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Расшифровка удержаний');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Списание за отзыв');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Без расшифровки WB');
        self::assertSelectorTextContains('#wb-deduction-breakdown', '10,00 RUB');
        self::assertSelectorCount(2, '#wb-deduction-breakdown tbody tr');
    }

    public function testDoesNotExposeRawRowsOfAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user] = $this->seedCompany(502);
        [, $otherCompany] = $this->seedCompany(503);
        $this->seedLoadedDay($otherCompany, new \DateTimeImmutable('2026-07-01'), [[
            'reportId' => 999999,
            'rrdId' => 999999,
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Чужая операция',
            'quantity' => 1,
            'retailPriceWithDisc' => '999999.99',
            'retailAmount' => '999999.99',
            'forPay' => '999999.99',
            'acquiringFee' => '0',
            'deduction' => '-42',
            'bonusTypeName' => 'Чужое удержание',
        ]]);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Чужая операция', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Чужое удержание', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('999999', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.alert-warning', 'нет строк по выбранным фильтрам');
    }

    public function testRejectsInvalidOrOversizedPeriod(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user] = $this->seedCompany(504);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-01-01&date_to=2026-07-01',
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Период отчёта не может превышать 93 дня');
    }

    public function testCsvUsesSameRawTotalsAndEscapesExternalSpreadsheetFormula(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(505);
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [
            [
                'reportId' => '=HYPERLINK("https://report.example.test")',
                'rrdId' => 2001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => '=HYPERLINK("https://example.test")',
                'quantity' => 1,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ],
            [
                'reportId' => 9002,
                'rrdId' => 2002,
                'sellerOperName' => 'Удержание',
                'deduction' => '-5',
                'bonusTypeName' => '=1+1',
            ],
        ]);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report/csv?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment;', (string) $client->getResponse()->headers->get('content-disposition'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"Расчётное перечисление";70.00', $content);
        self::assertStringContainsString('"Основание удержания WB"', $content);
        self::assertStringContainsString("'=1+1", $content);
        self::assertStringContainsString('\'=HYPERLINK', $content);
        self::assertStringContainsString('\'=HYPERLINK(""https://report.example.test"")', $content);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedCompany(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($user)
            ->build();

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$user, $company];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedLoadedDay(Company $company, \DateTimeImmutable $day, array $rows): void
    {
        $rawDocumentId = Uuid::uuid7()->toString();
        $rawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocumentId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build()
            ->setRawData($rows)
            ->setRecordsCount(count($rows));

        $status = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            (string) $company->getId(),
            Uuid::uuid7()->toString(),
            MarketplaceType::WILDBERRIES,
            'sales_report',
            'wildberries::sales_report',
            $day,
        );
        $status->markRawLoaded($rawDocumentId, count($rows), hash('sha256', serialize($rows)));
        $status->markSuccess();

        $this->em()->persist($rawDocument);
        $this->em()->persist($status);
        $this->em()->flush();
    }
}
