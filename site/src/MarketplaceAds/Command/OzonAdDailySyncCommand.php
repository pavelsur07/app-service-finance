<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\MarketplaceAds\Application\DispatchOzonAdLoadAction;
use App\MarketplaceAds\Infrastructure\Query\ActiveOzonPerformanceConnectionsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ежедневная загрузка Ozon Ads за вчерашний день для всех компаний
 * с активным Performance-подключением.
 *
 * Cron: 30 4 * * * php bin/console app:marketplace-ads:ozon-daily-sync
 *
 * Команда тонкая: получает список активных Ozon Performance подключений через DBAL Query,
 * для каждой компании вызывает {@see DispatchOzonAdLoadAction} с датой = «вчера».
 * Ошибки отдельной компании не должны прерывать обработку остальных.
 */
#[AsCommand(
    name: 'app:marketplace-ads:ozon-daily-sync',
    description: 'Ежедневная загрузка рекламы Ozon за вчерашний день по всем компаниям с активным Performance-подключением.',
)]
final class OzonAdDailySyncCommand extends Command
{
    public function __construct(
        private readonly ActiveOzonPerformanceConnectionsQuery $connectionsQuery,
        private readonly DispatchOzonAdLoadAction $dispatchAction,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyIds = $this->connectionsQuery->getCompanyIds();
        $total = count($companyIds);

        if (0 === $total) {
            $output->writeln('<info>Нет активных Ozon Performance подключений.</info>');

            return Command::SUCCESS;
        }

        $yesterday = (new \DateTimeImmutable('yesterday'))->setTime(0, 0);

        $dispatched = 0;
        foreach ($companyIds as $companyId) {
            try {
                ($this->dispatchAction)($companyId, $yesterday, $yesterday);
                ++$dispatched;
            } catch (\DomainException $e) {
                $this->logger->warning('Пропуск компании при ежедневной загрузке Ozon Ads', [
                    'companyId' => $companyId,
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Ошибка при ежедневной загрузке Ozon Ads', [
                    'companyId' => $companyId,
                    'error' => $e->getMessage(),
                ]);
            }

            $output->writeln(sprintf('Dispatched %d of %d companies', $dispatched, $total));
        }

        return Command::SUCCESS;
    }
}
