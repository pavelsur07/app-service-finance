<?php

// TODO: Удалить после использования — одноразовая команда для очистки фантомных снапшотов.

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application\Command;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'marketplace-analytics:cleanup-phantom-snapshots',
    description: 'Удаляет фантомные снапшоты по компании и маркетплейсу. Удалить после использования.',
)]
final class CleanupPhantomSnapshotsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('marketplace', null, InputOption::VALUE_REQUIRED, 'Маркетплейс (' . implode(', ', array_column(MarketplaceType::cases(), 'value')) . ')')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать количество без удаления');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId   = $input->getOption('company-id');
        $marketplace = $input->getOption('marketplace');
        $isDryRun    = (bool) $input->getOption('dry-run');

        try {
            Assert::uuid($companyId);
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>--company-id должен быть валидным UUID</error>');

            return Command::FAILURE;
        }

        $validValues = array_column(MarketplaceType::cases(), 'value');
        if (!in_array($marketplace, $validValues, true)) {
            $output->writeln('<error>--marketplace должен быть одним из: ' . implode(', ', $validValues) . '</error>');

            return Command::FAILURE;
        }

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM listing_daily_snapshots WHERE company_id = :companyId AND marketplace = :marketplace',
            ['companyId' => $companyId, 'marketplace' => $marketplace],
        );

        if ($isDryRun) {
            $output->writeln(sprintf(
                '<info>[dry-run] Найдено %d фантомных снапшотов (company=%s, marketplace=%s). Удаление не выполнено.</info>',
                $count,
                $companyId,
                $marketplace,
            ));

            return Command::SUCCESS;
        }

        if ($count === 0) {
            $output->writeln('<info>Фантомных снапшотов не найдено.</info>');

            return Command::SUCCESS;
        }

        $deleted = $this->connection->executeStatement(
            'DELETE FROM listing_daily_snapshots WHERE company_id = :companyId AND marketplace = :marketplace',
            ['companyId' => $companyId, 'marketplace' => $marketplace],
        );

        $output->writeln(sprintf(
            '<info>Удалено %d снапшотов. Запустите пересчёт.</info>',
            $deleted,
        ));

        return Command::SUCCESS;
    }
}
