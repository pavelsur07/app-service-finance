<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonPerformanceSearchPromoOrdersSummaryCommandTest extends IntegrationTestCase
{
    public function testSummarisesLatestVersionOfEachReportByDay(): void
    {
        $companyId = Uuid::uuid7()->toString();
        // Объект, сохранённый до починки разбора CSV: строки без колонок отчёта.
        $this->store($companyId, '2026-11-01:2026-11-06', [[';Отчёт по заказам' => 'Дата;ID заказа']]);
        // Две версии недельного окна: берётся последняя (расход заказа B изменился, добавился C).
        $this->store($companyId, '2026-11-01:2026-11-07', [
            self::order('03.11.2026', 'A', '111', '50,00'),
            self::order('04.11.2026', 'B', '222', '30,00'),
        ]);
        $this->store($companyId, '2026-11-01:2026-11-07', [
            self::order('03.11.2026', 'A', '111', '50,00'),
            self::order('04.11.2026', 'B', '222', '35,50'),
            self::order('05.11.2026', 'C', '', '1 010,00'),
        ]);
        // Перекрытие окон — тот же заказ не задваивается; 08.11 вне периода.
        $this->store($companyId, '2026-11-03:2026-11-08', [
            self::order('03.11.2026', 'A', '111', '50,00'),
            self::order('08.11.2026', 'D', '333', '70,00'),
        ]);
        // Чужая компания.
        $this->store(Uuid::uuid7()->toString(), '2026-11-01:2026-11-07', [self::order('03.11.2026', 'X', '999', '9999,00')]);

        $tester = $this->tester();
        $status = $tester->execute(['--company-id' => $companyId, '--from' => '2026-11-01', '--to' => '2026-11-07']);

        self::assertSame(Command::SUCCESS, $status);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('2026-11-03 1 50.00 0', $display);
        self::assertStringContainsString('2026-11-04 1 35.50 0', $display);
        self::assertStringContainsString('2026-11-05 1 1010.00 1', $display);
        self::assertStringNotContainsString('2026-11-08', $display);
        self::assertStringContainsString('Итого 3 1095.50 1', $display);
        self::assertStringContainsString('Отчётов прочитано: 3; разных SKU: 2; строк без даты или расхода: 1; заказанный SKU ≠ продвигаемому: 0.', $display);
        self::assertStringNotContainsString('9999', $display);
    }

    public function testRejectsInvalidPeriod(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => Uuid::uuid7()->toString(), '--from' => '01.11.2026', '--to' => '2026-11-07']));
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('app:ingestion:ozon-performance:search-promo-orders-summary'));
    }

    /**
     * @return array<string, string>
     */
    private static function order(string $day, string $orderId, string $sku, string $expense): array
    {
        return ['Дата' => $day, 'ID заказа' => $orderId, 'SKU' => $sku, 'Артикул' => 'ART', 'Расход, ₽' => $expense];
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function store(string $companyId, string $window, array $rows): void
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);
        $facade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: 'perf-connection',
            shopRef: 'perf-connection',
            source: IngestSource::OZON,
            resourceType: OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            externalId: 'performance-search-promo-stats:'.$window.':orders:batch:abc',
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable(),
            rows: $rows,
        ));
        // Версии различаются по last_seen_at: следующая сохраняется позже.
        usleep(2000);
    }
}
