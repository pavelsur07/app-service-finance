<?php

declare(strict_types=1);

namespace App\MarketplacePlProjection\Command;

use App\MarketplaceOperations\Facade\MarketplaceOperationsFacadeInterface;
use App\MarketplacePlProjection\Application\Action\SyncTemplateAction;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Правила компании «вид канона → статья ОПиУ» по шаблону платформы. Без `--execute` — только план.
 * В ОПиУ ничего не пишет: правила читает проекция.
 */
#[AsCommand(name: 'app:marketplace-pl-projection:sync-template', description: 'Syncs company preset P&L mapping rules with the platform template')]
final class SyncTemplateCommand extends Command
{
    public function __construct(
        private readonly SyncTemplateAction $sync,
        private readonly MarketplaceOperationsFacadeInterface $operations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Company UUID (repeatable)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every company with canonical marketplace operations')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Write the rules (default: dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $companyIds */
        $companyIds = $input->getOption('company-id');
        $execute = (bool) $input->getOption('execute');
        if ((bool) $input->getOption('all')) {
            $companyIds = $this->operations->companyIds();
            if ([] === $companyIds) {
                return self::SUCCESS;
            }
        }
        if ([] === $companyIds || [] !== array_filter($companyIds, static fn (string $id): bool => !Uuid::isValid($id))) {
            $output->writeln('нужен --all либо хотя бы один UUID в --company-id');

            return self::FAILURE;
        }

        foreach ($companyIds as $companyId) {
            $plan = ($this->sync)($companyId, $execute);
            $output->writeln(sprintf(
                '%s %s: created %d, updated %d, deleted %d, unchanged %d, custom kept %d%s',
                $execute ? 'applied' : 'dry-run',
                $companyId,
                $plan->created,
                $plan->updated,
                $plan->deleted,
                $plan->unchanged,
                $plan->custom,
                [] === $plan->missingCodes ? '' : '; no leaf category for: '.implode(', ', $plan->missingCodes),
            ));
        }

        return self::SUCCESS;
    }
}
