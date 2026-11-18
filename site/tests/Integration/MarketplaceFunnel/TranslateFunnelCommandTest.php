<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceFunnel;

use App\MarketplaceFunnel\Command\FunnelHealthCheckCommand;
use App\MarketplaceFunnel\Command\TranslateFunnelCommand;
use App\MarketplaceFunnel\Domain\OzonFunnelTranslator;
use App\MarketplaceFunnel\Domain\WbFunnelTranslator;
use App\MarketplaceFunnel\Infrastructure\Query\FunnelStore;
use App\SourceFacts\Facade\SourceFactsFacadeInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TranslateFunnelCommandTest extends IntegrationTestCase
{
    private const DAY = '2026-11-17';

    private string $companyId;
    private string $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid7()->toString();
        $this->otherCompanyId = Uuid::uuid7()->toString();
    }

    public function testTranslatesBothChannelsAndRepeatChangesNothing(): void
    {
        $this->facts($this->companyId, 'oz-1', OzonFunnelTranslator::RESOURCE, 1, ['ozon:analytics.funnel:11' => self::ozon('11', 100, 5), 'ozon:analytics.funnel:12' => self::ozon('12', 10, 0)]);
        $this->facts($this->companyId, 'wb-1', WbFunnelTranslator::RESOURCE, 1, ['wb:analytics.funnel:7' => self::wb(7, 40, 3)]);
        $this->facts($this->otherCompanyId, 'oz-2', OzonFunnelTranslator::RESOURCE, 1, ['ozon:analytics.funnel:11' => self::ozon('11', 999, 9)]);

        self::assertStringContainsString('3 due, 3 translated (4 rows), 0 failed', $this->translate());

        self::assertEquals([
            ['channel' => 'ozon', 'marketplace_sku' => '11', 'source' => 'total', 'impressions' => 100, 'card_views' => 0, 'orders' => 5],
            ['channel' => 'ozon', 'marketplace_sku' => '12', 'source' => 'total', 'impressions' => 10, 'card_views' => 0, 'orders' => 0],
            ['channel' => 'wildberries', 'marketplace_sku' => '7', 'source' => 'total', 'impressions' => null, 'card_views' => 40, 'orders' => 3],
        ], $this->rows($this->companyId), 'строки чужой компании не видны');
        self::assertSame(Command::SUCCESS, $this->healthCheck());

        self::assertStringContainsString('0 due', $this->translate());
        self::assertCount(3, $this->rows($this->companyId), 'повтор — те же строки');
    }

    public function testNewRevisionReplacesDayAndUnavailableDayHasNoRows(): void
    {
        $this->facts($this->companyId, 'oz-1', OzonFunnelTranslator::RESOURCE, 1, ['ozon:analytics.funnel:11' => self::ozon('11', 100, 5), 'ozon:analytics.funnel:12' => self::ozon('12', 10, 0)]);
        $this->translate();

        $this->facts($this->companyId, 'oz-1', OzonFunnelTranslator::RESOURCE, 2, ['ozon:analytics.funnel:11' => self::ozon('11', 120, 6)]);
        self::assertStringContainsString('1 due, 1 translated (1 rows)', $this->translate());
        self::assertSame([['channel' => 'ozon', 'marketplace_sku' => '11', 'source' => 'total', 'impressions' => 120, 'card_views' => 0, 'orders' => 6]], $this->rows($this->companyId), 'замена окна, а не дельта');

        $this->facts($this->companyId, 'oz-1', OzonFunnelTranslator::RESOURCE, 3, [OzonFunnelTranslator::UNAVAILABLE_KEY => ['subscription_required' => true]]);
        $this->translate();
        self::assertSame([], $this->rows($this->companyId));
        self::assertFalse((bool) $this->connection->fetchOne('SELECT available FROM mfn_day WHERE company_id = ?', [$this->companyId]), 'нет тарифа — «нет данных», а не нули');
    }

    public function testGateSeesLostRowsUntranslatedDaysAndBrokenFacts(): void
    {
        $this->facts($this->companyId, 'oz-1', OzonFunnelTranslator::RESOURCE, 1, ['ozon:analytics.funnel:11' => self::ozon('11', 100, 5)]);
        self::assertSame(Command::FAILURE, $this->healthCheck(), 'день с фактами не переведён');

        $this->translate();
        self::assertSame(Command::SUCCESS, $this->healthCheck());

        $this->connection->executeStatement('DELETE FROM mfn_row WHERE company_id = ?', [$this->companyId]);
        self::assertSame(Command::FAILURE, $this->healthCheck(), 'строка таблицы потеряна');

        $this->facts($this->otherCompanyId, 'oz-2', OzonFunnelTranslator::RESOURCE, 1, ['ozon:analytics.funnel:11' => ['dimensions' => [['id' => '11']], 'metrics' => [1]]]);
        self::assertStringContainsString('1 failed', $this->translate(), 'день с записью другой формы не переводится частично');
        self::assertSame([], $this->rows($this->otherCompanyId));
    }

    /** @return array<string, mixed> */
    private static function ozon(string $sku, int $impressions, int $orders): array
    {
        $metrics = array_fill(0, 14, 0);
        $metrics[2] = $impressions;
        $metrics[9] = $orders;

        return ['dimensions' => [['id' => $sku, 'name' => 'Товар']], 'metrics' => $metrics];
    }

    /** @return array<string, mixed> */
    private static function wb(int $nmId, int $opens, int $orders): array
    {
        return ['product' => ['nmId' => $nmId], 'statistic' => ['selected' => ['openCount' => $opens, 'cartCount' => 0, 'orderCount' => $orders, 'orderSum' => 0, 'buyoutCount' => 0, 'cancelCount' => 0]]];
    }

    /**
     * @param array<string, array<string, mixed>> $rows source_key → payload
     */
    private function facts(string $companyId, string $cabinet, string $resource, int $revision, array $rows): void
    {
        $period = ['company' => $companyId, 'connection' => $cabinet, 'resource' => $resource, 'period' => self::DAY];
        foreach ($rows as $key => $row) {
            $this->connection->executeStatement(
                'INSERT INTO sf_fact (id, company_id, connection_ref, resource_type, period_key, source_key, payload_hash, payload, first_revision, last_seen_revision, created_at)
                 VALUES (gen_random_uuid(), :company, :connection, :resource, :period, :key, md5(:payload), CAST(:payload AS jsonb), :revision, :revision, now())',
                $period + ['key' => $key, 'payload' => json_encode($row, \JSON_THROW_ON_ERROR), 'revision' => $revision],
            );
        }
        $this->connection->executeStatement(
            'INSERT INTO sf_period (id, company_id, connection_ref, resource_type, period_key, current_revision, fact_count, updated_at)
             VALUES (gen_random_uuid(), :company, :connection, :resource, :period, :revision, :count, now())
             ON CONFLICT (company_id, connection_ref, resource_type, period_key) DO UPDATE SET current_revision = EXCLUDED.current_revision, fact_count = EXCLUDED.fact_count',
            $period + ['revision' => $revision, 'count' => \count($rows)],
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $companyId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT channel, marketplace_sku, source, impressions, card_views, orders FROM mfn_row WHERE company_id = ? ORDER BY channel, marketplace_sku',
            [$companyId],
        );
    }

    private function translate(): string
    {
        $c = self::getContainer();
        $tester = new CommandTester(new TranslateFunnelCommand($c->get(SourceFactsFacadeInterface::class), $c->get(FunnelStore::class), $c->get(Connection::class), new NullLogger()));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function healthCheck(): int
    {
        $c = self::getContainer();

        return (new CommandTester(new FunnelHealthCheckCommand($c->get(SourceFactsFacadeInterface::class), $c->get(FunnelStore::class), new NullLogger())))->execute([]);
    }
}
