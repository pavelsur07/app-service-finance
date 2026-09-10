<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveOzonConnectionsQuery;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ежедневная загрузка финансовых отчётов Ozon через /v1/finance/accrual/by-day.
 *
 * Имя парное к `app:marketplace:wb-financial-reports:sync` — та же работа для
 * другого маркетплейса. Сегмент `ozon-accrual:` намеренно НЕ используется: под
 * ним живут пятнадцать команд Ingestion, и одноимённый сегмент под другим
 * префиксом читался бы как та же семья в кроне, allowlist и логах.
 *
 * Замена app:marketplace:ozon-daily-sync, чей источник Ozon снял 09.09.2026.
 * Команда тонкая: берёт активные Ozon-подключения и на каждый день окна шлёт
 * сообщение; вся загрузка — в SyncOzonAccrualByDayHandler.
 *
 * Окно по умолчанию 14 дней, как у легаси: Ozon правит начисления задним
 * числом, и однодневное окно эти правки терять.
 *
 * ВНИМАНИЕ на переходный период. Дни по 07.09.2026 включительно уже покрыты
 * документами снятого формата v3 (`sales_report`), и их продажи лежат в
 * `marketplace_sales`. Документы by-day с ними не конфликтуют — у них свой
 * `document_type`, — но нормализованные строки не разводятся: уникальность
 * `marketplace_sales` построена на `external_order_id`, а ключи у путей разные
 * (`posting_number` против `ozon-accrual-{posting}-product-{i}`). День,
 * обработанный из обоих документов, даст двойной учёт продаж. Поэтому крон
 * ходит с `--days-back=2`, а широкое окно вручную до 22.09.2026 не запускать.
 *
 * `--company-id` ограничивает прогон одним кабинетом — для пилота и перезалива.
 */
#[AsCommand(
    name: 'app:marketplace:ozon-financial-reports:sync',
    description: 'Загрузка начислений Ozon (accrual by-day) за скользящее окно дней',
)]
final class OzonFinancialReportsSyncCommand extends Command
{
    private const DEFAULT_LOOKBACK_DAYS = 14;
    private const MAX_LOOKBACK_DAYS = 365;

    public function __construct(
        private readonly ActiveOzonConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days-back',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Глубина окна в днях, 1..%d.', self::MAX_LOOKBACK_DAYS),
                (string) self::DEFAULT_LOOKBACK_DAYS,
            )
            ->addOption(
                'company-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Загрузить только по одной компании (UUID). Без опции — все активные кабинеты.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $daysBack = (int) $input->getOption('days-back');
        if ($daysBack < 1 || $daysBack > self::MAX_LOOKBACK_DAYS) {
            $io->error(sprintf('--days-back должен быть в диапазоне 1..%d.', self::MAX_LOOKBACK_DAYS));

            return Command::FAILURE;
        }

        $companyId = $input->getOption('company-id');
        $companyFilter = is_string($companyId) && '' !== trim($companyId) ? trim($companyId) : null;

        $connections = $this->connectionsQuery->execute($companyFilter);

        if ([] === $connections) {
            // Запрошенный кабинет без активного подключения — это не «работы нет»,
            // а несбывшееся намерение оператора: молчаливый успех спрятал бы опечатку
            // в UUID ровно тогда, когда команду запускают руками для перезалива.
            if (null !== $companyFilter) {
                $io->error(sprintf('Нет активного Ozon-подключения (seller) для компании %s.', $companyFilter));

                return Command::FAILURE;
            }

            $io->info('Нет активных Ozon-подключений для синхронизации.');

            return Command::SUCCESS;
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
        $dispatched = 0;

        foreach ($connections as $row) {
            $rowCompanyId = (string) $row['company_id'];
            $connectionId = (string) $row['id'];

            for ($offset = 1; $offset <= $daysBack; ++$offset) {
                $date = $today->modify(sprintf('-%d day', $offset))->format('Y-m-d');

                $this->messageBus->dispatch(new SyncOzonAccrualByDayMessage($rowCompanyId, $connectionId, $date));
                ++$dispatched;

                $this->logger->info('Dispatched Ozon accrual by-day sync message', [
                    'company_id' => $rowCompanyId,
                    'connection_id' => $connectionId,
                    'date' => $date,
                ]);
            }
        }

        $io->success(sprintf('Отправлено %d задач на загрузку начислений Ozon за последние %d дней.', $dispatched, $daysBack));

        return Command::SUCCESS;
    }
}
