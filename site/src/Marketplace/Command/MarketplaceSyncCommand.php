<?php

namespace App\Marketplace\Command;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use App\Marketplace\Service\MarketplaceSyncService;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'marketplace:sync',
    description: 'Синхронизация данных с маркетплейсами'
)]
class MarketplaceSyncCommand extends Command
{
    public function __construct(
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly WildberriesAdapter $wbAdapter,
        private readonly MarketplaceSyncService $syncService,
        private readonly UserRepository $userRepository,
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

            $connection = $this->connectionRepository->findByMarketplace($company, $marketplace);

            if (!$connection || !$connection->isActive()) {
                $io->warning('Подключение неактивно, пропускаем');
                continue;
            }

            try {
                $adapter = match ($marketplace) {
                    MarketplaceType::WILDBERRIES => $this->wbAdapter,
                    default => throw new \RuntimeException('Адаптер не реализован'),
                };

                $io->text('Синхронизация продаж...');
                $salesCount = $this->syncService->syncSales($company, $adapter, $fromDate, $toDate);
                $totalSales += $salesCount;
                $io->success("✓ Продаж: {$salesCount}");

                $io->text('Синхронизация затрат...');
                $costsCount = $this->syncService->syncCosts($company, $adapter, $fromDate, $toDate);
                $totalCosts += $costsCount;
                $io->success("✓ Затрат: {$costsCount}");

                $io->text('Синхронизация возвратов...');
                $returnsCount = $this->syncService->syncReturns($company, $adapter, $fromDate, $toDate);
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
        // Simplified - в реальности нужен CompanyRepository
        $user = $this->userRepository->findOneBy(['id' => $id]);

        return $user->getCompanies()->first();
    }

    private function getAllCompaniesWithConnection(MarketplaceType $marketplace): array
    {
        // Simplified - получить все компании с активным подключением
        $connections = $this->connectionRepository->findBy([
            'marketplace' => $marketplace,
            'isActive' => true,
        ]);

        return array_map(fn ($c) => $c->getCompany(), $connections);
    }
}
