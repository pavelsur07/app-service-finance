<?php

declare(strict_types=1);

namespace App\Marketplace\Command;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceSyncFacade;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'marketplace:sync',
    description: 'Синхронизация данных с маркетплейсами'
)]
/**
 * @deprecated Legacy CLI sync command.
 *
 * Основной WB initial/daily/manual sync использует WildberriesAdapter.
 * Этот pipeline оставлен только для обратной совместимости; код не удалять.
 */
class MarketplaceSyncCommand extends Command
{
    private const WB_FINANCIAL_REPORTS_SYNC_COMMAND = 'app:marketplace:wb-financial-reports:sync';

    public function __construct(
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly MarketplaceSyncFacade $syncFacade,
        private readonly CompanyFacade $companyFacade,
        #[Autowire(service: 'monolog.logger.legacy_wb_sync')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('marketplace', InputArgument::OPTIONAL, 'Маркетплейс (wildberries, ozon)', 'wildberries')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Количество дней для синхронизации', 7)
            ->addOption('company-id', 'c', InputOption::VALUE_OPTIONAL, 'ID компании (опционально)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $marketplaceValue = $input->getArgument('marketplace');
        $days = (int) $input->getOption('days');
        $companyId = $input->getOption('company-id');

        try {
            $marketplace = MarketplaceType::from($marketplaceValue);
        } catch (\ValueError $e) {
            $io->error('Неизвестный маркетплейс: '.$marketplaceValue);

            return Command::FAILURE;
        }

        if (MarketplaceType::WILDBERRIES === $marketplace) {
            $this->logger->error('Legacy WB sync fail-fast triggered.', [
                'legacy_event' => 'legacy_wb_sync_fail_fast',
                'company_id' => is_string($companyId) && '' !== $companyId ? $companyId : null,
                'connection_id' => null,
                'command_class' => self::class,
                'message_class' => null,
                'recommended_replacement' => sprintf('%s (%s)', WbFinancialReportsSyncCommand::class, self::WB_FINANCIAL_REPORTS_SYNC_COMMAND),
            ]);

            $io->error(sprintf(
                'Legacy WB sync отключён. Используйте новую команду: %s',
                self::WB_FINANCIAL_REPORTS_SYNC_COMMAND,
            ));

            return Command::FAILURE;
        }

        $io->title('Синхронизация с '.$marketplace->getDisplayName());

        // Получить компании для синхронизации
        if ($companyId) {
            $companies = [$this->getCompanyById($companyId)];
        } else {
            $companies = $this->getAllCompaniesWithConnection($marketplace);
        }

        if (empty($companies)) {
            $io->warning('Нет компаний с активными подключениями к '.$marketplace->getDisplayName());

            return Command::SUCCESS;
        }

        $fromDate = new \DateTimeImmutable("-{$days} days");
        $toDate = new \DateTimeImmutable();

        $io->info(sprintf('Период: %s - %s', $fromDate->format('d.m.Y'), $toDate->format('d.m.Y')));

        $totalSales = 0;
        $totalCosts = 0;
        $totalReturns = 0;

        foreach ($companies as $company) {
            $io->section('Компания: '.$company->getName());

            $connection = $this->connectionRepository->findByMarketplace(
                $company,
                $marketplace,
                MarketplaceConnectionType::SELLER,
            );

            if (!$connection || !$connection->isActive()) {
                $io->warning('Подключение неактивно, пропускаем');
                continue;
            }

            try {
                $io->text('Синхронизация продаж...');
                $salesCount = $this->syncFacade->syncSales((string) $company->getId(), $marketplace, $fromDate, $toDate);
                $totalSales += $salesCount;
                $io->success("✓ Продаж: {$salesCount}");

                $io->text('Синхронизация затрат...');
                $costsCount = $this->syncFacade->syncCosts((string) $company->getId(), $marketplace, $fromDate, $toDate);
                $totalCosts += $costsCount;
                $io->success("✓ Затрат: {$costsCount}");

                $io->text('Синхронизация возвратов...');
                $returnsCount = $this->syncFacade->syncReturns((string) $company->getId(), $marketplace, $fromDate, $toDate);
                $totalReturns += $returnsCount;
                $io->success("✓ Возвратов: {$returnsCount}");
            } catch (\Exception $e) {
                $io->error('Ошибка: '.$e->getMessage());
            }
        }

        $io->success(sprintf(
            'Синхронизация завершена. Всего: %d продаж, %d затрат, %d возвратов',
            $totalSales,
            $totalCosts,
            $totalReturns
        ));

        return Command::SUCCESS;
    }

    private function getCompanyById(string $id): Company
    {
        $company = $this->companyFacade->findById($id);
        if (null === $company) {
            throw new \InvalidArgumentException("Company not found: {$id}");
        }

        return $company;
    }

    private function getAllCompaniesWithConnection(MarketplaceType $marketplace): array
    {
        // Simplified - получить все компании с активным подключением
        $connections = $this->connectionRepository->findBy([
            'marketplace' => $marketplace,
            'isActive' => true,
        ]);

        return array_map(static fn ($c) => $c->getCompany(), $connections);
    }
}
