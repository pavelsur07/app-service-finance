<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonRawDuplicatesAuditCommandTest extends IntegrationTestCase
{
    public function testTableAndJsonAndInvalidDate(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(1)->build();
        $this->em->persist($company);
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withIndex(1)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'))->build());
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withIndex(2)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withDocumentType('sales_report')->withPeriod(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-30'))->build());
        $this->em->flush();

        $tester = $this->tester();
        $ok = $tester->execute(['--from' => '2026-04-01', '--to' => '2026-04-30']);
        self::assertSame(Command::SUCCESS, $ok);
        $out = $tester->getDisplay();
        self::assertStringContainsString('Exact raw document duplicates', $out);
        self::assertStringContainsString('Overlapping raw documents', $out);
        self::assertStringContainsString('2026-04-01', $out);

        $tester = $this->tester();
        $okJson = $tester->execute(['--from' => '2026-04-01', '--to' => '2026-04-30', '--format' => 'json']);
        self::assertSame(Command::SUCCESS, $okJson);
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        foreach (['exact_raw_duplicates','overlapping_raw_documents','processed_sales_duplicates','processed_returns_duplicates','processed_costs_duplicates'] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
        self::assertGreaterThanOrEqual(1, count($payload['exact_raw_duplicates']));
        self::assertArrayHasKey('company_id', $payload['exact_raw_duplicates'][0]);
        self::assertArrayHasKey('period_from', $payload['exact_raw_duplicates'][0]);
        self::assertArrayHasKey('period_to', $payload['exact_raw_duplicates'][0]);
        self::assertArrayHasKey('docs_count', $payload['exact_raw_duplicates'][0]);

        $tester = $this->tester();
        $fail = $tester->execute(['--from' => '2026-05-01', '--to' => '2026-04-01']);
        self::assertSame(Command::FAILURE, $fail);
        self::assertStringContainsString('меньше или равна', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);
        return new CommandTester($app->find('app:marketplace:ozon-raw-duplicates-audit'));
    }
}
