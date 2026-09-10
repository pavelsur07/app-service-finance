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
 * «загрузка встала сейчас». Сегодняшний день намеренно не требуется: Ozon
 * закрывает начисления суткам задним числом, и загрузчик ходит за вчера и глубже.
 *
 * Спрашивается ровно вчерашний день, а не «последний загруженный»: документ за
 * сегодня не доказывает, что вчерашний на месте.
 *
 * Засчитывается только обработанный документ. Под каждое состояние, которое гейт
 * помечает плохим, есть операция, переводящая его в хорошее: день без документа
 * чинит `ozon-financial-reports:sync` (её окно вчерашний день покрывает), день с
 * незавершённой обработкой — `app:marketplace:reprocess`.
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

        $withProcessedDay = array_flip($this->latestDocumentQuery->findCompaniesWithProcessedDay(
            $companyIds,
            SyncOzonAccrualByDayHandler::DOCUMENT_TYPE,
            $expectedDay,
        ));

        $missingCount = 0;

        foreach ($companyIds as $companyId) {
            if (!isset($withProcessedDay[$companyId])) {
                ++$missingCount;
                $output->writeln(sprintf('MISSING company %s: нет обработанного документа начислений за %s', $companyId, $expectedDay));

                continue;
            }

            $output->writeln(sprintf('OK company %s: день %s закрыт', $companyId, $expectedDay));
        }

        $output->writeln(sprintf('checked companies count: %d', count($companyIds)));
        $output->writeln(sprintf('expected day: %s', $expectedDay));
        $output->writeln(sprintf('missing count: %d', $missingCount));
        $output->writeln('finish');

        if (0 === $missingCount) {
            return self::SUCCESS;
        }

        // Один агрегированный error со счётчиком, а не запись на кабинет: встала
        // загрузка финансовых отчётов — инцидент, который сам не рассосётся,
        // и человека будить нужно один раз.
        $this->logger->error('Ozon financial reports are not loaded for active seller connections.', [
            'checked_companies' => count($companyIds),
            'expected_day' => $expectedDay,
            'missing_count' => $missingCount,
        ]);

        return self::FAILURE;
    }
}
