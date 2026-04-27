<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ежедневная пересборка предварительного ОПиУ за текущий открытый месяц.
 *
 * Cron: 30 4 * * * php bin/console app:marketplace:month-preliminary-rebuild
 * (после Ozon daily sync 04:00 — данные за вчера к этому моменту уже подтянуты)
 *
 * Команда тонкая: получает все активные SELLER-подключения и для каждого
 * диспатчит RebuildPreliminaryForPeriodMessage. Вся бизнес-логика —
 * в RebuildPreliminaryForPeriodHandler / -Action.
 *
 * Системный actorUserId — фиксированный UUID '00000000-0000-0000-0000-000000000001'.
 * Поле stageXxxClosedByUserId в MarketplaceMonthClose — guid nullable БЕЗ FK,
 * поэтому несуществующий пользователь не сломает закрытие.
 */
#[AsCommand(
    name: 'app:marketplace:month-preliminary-rebuild',
    description: 'Ежедневная пересборка предварительного ОПиУ за текущий месяц для всех активных подключений',
)]
final class MonthPreliminaryRebuildCommand extends Command
{
    use LockableTrait;

    public const SYSTEM_ACTOR_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(
        private readonly ActiveSellerConnectionsQuery $connectionsQuery,
        private readonly MessageBusInterface          $messageBus,
        private readonly LoggerInterface              $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Команда уже запущена другим процессом — пропускаем.');

            return Command::SUCCESS;
        }

        try {
            $now   = new \DateTimeImmutable();
            $year  = (int) $now->format('Y');
            $month = (int) $now->format('n');

            $connections = $this->connectionsQuery->execute();

            if (empty($connections)) {
                $io->info('Нет активных SELLER-подключений для пересборки.');

                return Command::SUCCESS;
            }

            $dispatched = 0;
            $failed     = 0;

            foreach ($connections as $row) {
                $companyId   = (string) $row['company_id'];
                $marketplace = (string) $row['marketplace'];

                try {
                    $this->messageBus->dispatch(new RebuildPreliminaryForPeriodMessage(
                        companyId:   $companyId,
                        marketplace: $marketplace,
                        year:        $year,
                        month:       $month,
                        actorUserId: self::SYSTEM_ACTOR_USER_ID,
                    ));

                    $dispatched++;

                    $this->logger->info('[PreliminaryRebuild] Dispatched', [
                        'company_id'  => $companyId,
                        'marketplace' => $marketplace,
                        'year'        => $year,
                        'month'       => $month,
                    ]);
                } catch (\Throwable $e) {
                    // Сбой одного диспатча не должен прерывать остальные.
                    $failed++;

                    $this->logger->error('[PreliminaryRebuild] Dispatch failed', [
                        'company_id'  => $companyId,
                        'marketplace' => $marketplace,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            $io->success(sprintf(
                'Отправлено %d задач предзакрытия (ошибок: %d), период: %d-%02d.',
                $dispatched,
                $failed,
                $year,
                $month,
            ));

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
