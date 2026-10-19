<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionDirection;
use App\Ingestion\Enum\TransactionType;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Kernel\IntegrationTestCase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualImportDetailsXlsxCommandTest extends IntegrationTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/ozon-details-cmd-'.bin2hex(random_bytes(6)).'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function testDryRunThenExecute(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $this->byDay($companyId, '2026-08-01', '111', 17400, 76);
        $this->byDay($companyId, '2026-08-02', '111', 5000, 76);
        $writer = new Writer();
        $writer->openToFile($this->path);
        $writer->addRow(Row::fromValues(['Период: 01.08.2026-31.08.2026']));
        $writer->addRow(Row::fromValues(['ID начисления', 'Дата начисления', 'Группа услуг', 'Тип начисления', 'SKU', 'Сумма итого, руб.']));
        $writer->addRow(Row::fromValues(['1', '01.08.2026', 'Услуги партнёров', 'Страхование товара от массовых повреждений', 111.0, -174.0]));
        $writer->addRow(Row::fromValues(['2', '02.08.2026', 'Услуги партнёров', 'Страхование товара от массовых повреждений', 111.0, -50.0]));
        $writer->close();

        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute(['--company-id' => $companyId, '--file' => $this->path, '--dry-run' => true]));
        self::assertStringContainsString('Dry-run only', $dryRun->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ingest_ozon_accrual_type_groups'));

        $execute = $this->tester();
        self::assertSame(Command::SUCCESS, $execute->execute(['--company-id' => $companyId, '--file' => $this->path]));
        self::assertStringContainsString('Confirmed groups: 1.', $execute->getDisplay());
        self::assertSame('partners', $this->connection->fetchOne('SELECT group_code FROM ingest_ozon_accrual_type_groups WHERE type_id = 76'));
    }

    public function testMissingFileFails(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--company-id' => Uuid::uuid7()->toString(), '--file' => $this->path]));
        self::assertStringContainsString('not readable', $tester->getDisplay());
    }

    private function byDay(string $companyId, string $date, string $sku, int $amountMinor, int $typeId): void
    {
        $this->em->persist(new FinancialTransaction(
            companyId: $companyId,
            connectionRef: $companyId,
            shopRef: $companyId,
            source: IngestSource::OZON,
            externalId: sprintf('ozon:accrual-by-day:%s:%s:%d', $date, $sku, $typeId),
            externalUpdatedAt: new \DateTimeImmutable('2026-09-01 03:00:00'),
            operationGroupId: Uuid::uuid7()->toString(),
            type: TransactionType::FEE,
            direction: TransactionDirection::OUT,
            money: Money::fromMinor(-$amountMinor, 'RUB'),
            occurredAt: new \DateTimeImmutable($date.' 00:00:00'),
            rawRecordId: Uuid::uuid7()->toString(),
            sourceData: ['_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY, '_ingestion_type_id' => (string) $typeId, 'sku' => $sku],
        ));
        $this->em->flush();
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('app:ingestion:ozon-accrual:import-details-xlsx'));
    }
}
