<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Infrastructure\Query\LatestOzonAccrualDocumentQuery;
use App\Marketplace\MessageHandler\SyncOzonAccrualByDayHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт свежести финансовых отчётов Ozon: у активного seller-подключения обязан
 * быть документ начислений за вчерашний день.
 *
 * Охват проверки равен охвату починки. Проверяются ровно те кабинеты, по
 * которым ходит `app:marketplace:ozon-financial-reports:sync` — активные
 * seller-подключения из `ActiveOzonConnectionsQuery`; `performance`-подключения
 * рекламы загрузку начислений не запускают, и включение их в гейт сделало бы
 * его вечно красным.
 *
 * Порог — вчера, а не «есть ли дыры в истории»: ненулевой exit code означает
 * «загрузка встала сейчас». Починка под этот гейт — та же команда загрузки с
 * окном 2 дня, которое вчерашний день покрывает.
 *
 * Сегодняшний день намеренно не требуется: Ozon закрывает начисления суткам
 * задним числом, и загрузчик ходит за вчера и глубже.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-financial-reports:freshness-check',
    description: 'Read-only gate: каждое активное seller-подключение Ozon должно иметь документ начислений за вчера',
)]
final class OzonFinancialReportsFreshnessCheckCommand extends Command
{
    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly LatestOzonAccrualDocumentQuery $latestDocumentQuery,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('start');

        $connections = $this->connectionsQuery->execute();

        if ([] === $connections) {
            $output->writeln('active seller connections count: 0');
            $output->writeln('finish');

            return self::SUCCESS;
        }

        $companyIds = [];
        foreach ($connections as $row) {
            $companyId = (string) $row['company_id'];
            if ('' !== $companyId) {
                $companyIds[$companyId] = true;
            }
        }
        $companyIds = array_keys($companyIds);

        $expectedDay = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))
            ->modify('-1 day')
            ->format('Y-m-d');

        $latestByCompany = $this->latestDocumentQuery->findLatestByCompanyIds(
            $companyIds,
            SyncOzonAccrualByDayHandler::DOCUMENT_TYPE,
        );

        $missingCount = 0;
        $staleCount = 0;

        foreach ($companyIds as $companyId) {
            $lastDay = $latestByCompany[$companyId] ?? null;

            if (null === $lastDay) {
                ++$missingCount;
                $output->writeln(sprintf('MISSING company %s: нет ни одного документа начислений', $companyId));

                continue;
            }

            if ($lastDay < $expectedDay) {
                ++$staleCount;
                $output->writeln(sprintf('STALE company %s: последний день %s, ожидался %s', $companyId, $lastDay, $expectedDay));

                continue;
            }

            $output->writeln(sprintf('OK company %s: последний день %s', $companyId, $lastDay));
        }

        $output->writeln(sprintf('checked companies count: %d', count($companyIds)));
        $output->writeln(sprintf('expected day: %s', $expectedDay));
        $output->writeln(sprintf('stale count: %d', $staleCount));
        $output->writeln(sprintf('missing count: %d', $missingCount));
        $output->writeln('finish');

        $failedCount = $staleCount + $missingCount;
        if (0 === $failedCount) {
            return self::SUCCESS;
        }

        // Один агрегированный error со счётчиком, а не запись на кабинет: встала
        // загрузка финансовых отчётов — инцидент, который сам не рассосётся,
        // и человека будить нужно один раз.
        $this->logger->error('Ozon financial reports are not loaded for active seller connections.', [
            'checked_companies' => count($companyIds),
            'expected_day' => $expectedDay,
            'stale_count' => $staleCount,
            'missing_count' => $missingCount,
        ]);

        return self::FAILURE;
    }
}
