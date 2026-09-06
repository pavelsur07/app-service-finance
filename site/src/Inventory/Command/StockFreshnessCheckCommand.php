<?php

declare(strict_types=1);

namespace App\Inventory\Command;

use App\Inventory\Domain\StockSnapshotFreshnessPolicy;
use App\Inventory\Infrastructure\Query\LatestStockSnapshotDateQuery;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт свежести остатков: у активного подключения обязан быть снапшот за окно порога.
 *
 * Охват проверки намеренно равен охвату починки: проверяются только компании с
 * активным подключением нужного маркетплейса. У компании без подключения загрузка
 * не запускается вовсе, чинить нечего, и включение её в гейт сделало бы его вечно
 * красным — это ложный алерт, обесценивающий канал целиком.
 *
 * Проверка идёт по свежести, а не по накопленному объёму исторических дыр:
 * ненулевой exit code означает «загрузка встала сейчас», а не «когда-то были пропуски».
 */
#[AsCommand(
    name: 'app:inventory:stock-freshness-check',
    description: 'Read-only gate: every active marketplace connection must have a recent stock snapshot',
)]
final class StockFreshnessCheckCommand extends Command
{
    public function __construct(
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly LatestStockSnapshotDateQuery $latestSnapshotDateQuery,
        private readonly StockSnapshotFreshnessPolicy $freshnessPolicy,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        $today = new \DateTimeImmutable('today');
        $expected = $this->expectedCompanySourcePairs();

        if ([] === $expected) {
            $output->writeln('active connections count: 0');
            $output->writeln('finish');

            return self::SUCCESS;
        }

        $companyIds = array_values(array_unique(array_map(
            static fn (array $pair): string => $pair['companyId'],
            $expected,
        )));

        $latestByCompany = $this->latestSnapshotDateQuery->findLatestByCompanyIds($companyIds, $today);

        $staleCount = 0;
        $missingCount = 0;

        foreach ($expected as $pair) {
            $companyId = $pair['companyId'];
            $source = $pair['source'];
            $snapshotDate = $latestByCompany[$companyId][$source] ?? null;

            if (null === $snapshotDate) {
                ++$missingCount;
                // Именно «на дату проверки или раньше»: снапшот с датой из будущего
                // в выборку не попадает, и обещать его отсутствие вообще было бы неверно.
                $output->writeln(sprintf(
                    'MISSING company %s source %s: no stock snapshot on or before %s',
                    $companyId,
                    $source,
                    $today->format('Y-m-d'),
                ));

                continue;
            }

            if ($this->freshnessPolicy->isStale(new \DateTimeImmutable($snapshotDate), $today)) {
                ++$staleCount;
                $output->writeln(sprintf('STALE company %s source %s: last snapshot %s', $companyId, $source, $snapshotDate));

                continue;
            }

            $output->writeln(sprintf('OK company %s source %s: last snapshot %s', $companyId, $source, $snapshotDate));
        }

        $output->writeln(sprintf('checked pairs count: %d', count($expected)));
        $output->writeln(sprintf('stale count: %d', $staleCount));
        $output->writeln(sprintf('missing count: %d', $missingCount));
        $output->writeln(sprintf('max age days: %d', $this->freshnessPolicy->maxAgeDays()));
        $output->writeln('finish');

        $failedCount = $staleCount + $missingCount;
        if (0 === $failedCount) {
            return self::SUCCESS;
        }

        // Один агрегированный error со счётчиком, а не запись на каждую пару:
        // встала загрузка остатков у действующего подключения — это инцидент,
        // который сам не рассосётся, и человека будить нужно один раз.
        $this->logger->error('Inventory stock snapshots are not fresh for active connections.', [
            'checked_pairs' => count($expected),
            'stale_count' => $staleCount,
            'missing_count' => $missingCount,
            'max_age_days' => $this->freshnessPolicy->maxAgeDays(),
        ]);

        return self::FAILURE;
    }

    /**
     * @return list<array{companyId: string, source: string}>
     */
    private function expectedCompanySourcePairs(): array
    {
        $pairs = [];
        $seen = [];

        $sources = [
            MarketplaceType::OZON->value => $this->marketplaceFacade->getActiveOzonSellerConnections(),
            MarketplaceType::WILDBERRIES->value => $this->marketplaceFacade->getActiveWbSellerConnections(),
        ];

        foreach ($sources as $source => $connections) {
            foreach ($connections as $connection) {
                $companyId = (string) $connection['companyId'];
                if ('' === $companyId) {
                    continue;
                }

                $key = $companyId."\0".$source;
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $pairs[] = ['companyId' => $companyId, 'source' => (string) $source];
            }
        }

        return $pairs;
    }
}
