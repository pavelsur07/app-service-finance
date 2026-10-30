<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Enum\Transaction\CashCategorySuggestionStatus;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

/**
 * Read-only: статусы ИИ-подсказок компании и точность по решениям людей (основание для порога §6).
 */
#[AsCommand(
    name: 'app:cash:ai-categorize-stats',
    description: 'Shows AI category suggestion statuses and precision by human decisions for a company (read-only)',
)]
final class CashAiCategorizeStatsCommand extends Command
{
    public function __construct(private readonly CashCategorizationQuery $query)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company-id');
        try {
            Assert::uuid($companyId, '--company-id must be a UUID');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        $stats = $this->query->stats($companyId);
        foreach (CashCategorySuggestionStatus::cases() as $status) {
            $output->writeln(sprintf('%-10s %d', $status->value, $stats->count($status->value)));
        }
        $output->writeln(sprintf('corrected  %d (another category set by editing the operation)', $stats->corrected));
        $output->writeln(sprintf('confirmed  %d (same category set by editing the operation)', $stats->confirmedByEdit));
        $precision = $stats->precision();
        $output->writeln('precision  '.(null === $precision ? 'n/a' : sprintf('%.1f%%', 100 * $precision)));

        return self::SUCCESS;
    }
}
