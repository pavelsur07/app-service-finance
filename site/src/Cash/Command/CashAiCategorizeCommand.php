<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\RequestCashAiCategorizationAction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

/**
 * Постановка ИИ-подсказок статей ДДС для операций компании без статьи.
 * Ничего не меняет в операциях; выключенный ИИ-сервис для компании — ничего не ставит.
 */
#[AsCommand(
    name: 'app:cash:ai-categorize',
    description: 'Requests AI category suggestions for uncategorized cash transactions of a company',
)]
final class CashAiCategorizeCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly RequestCashAiCategorizationAction $action)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max operations per run (1..1000)', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company-id');
        $limit = (int) $input->getOption('limit');
        try {
            Assert::uuid($companyId, '--company-id must be a UUID');
            Assert::range($limit, 1, 1000, '--limit must be 1..1000');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        if (!$this->lock($this->getName().':'.$companyId)) {
            $output->writeln('ai-categorize: another run for this company is in progress, skipping');

            return self::SUCCESS;
        }

        try {
            $result = ($this->action)($companyId, $limit);
        } finally {
            $this->release();
        }

        if (!$result->enabled) {
            $output->writeln('ai-categorize: ai-service is disabled for the company');

            return self::SUCCESS;
        }
        $output->writeln(sprintf(
            'ai-categorize: %d operations in %d tasks requested, %d tasks re-sent, %d requests expired%s',
            $result->requested,
            $result->tasks,
            $result->redispatched,
            $result->expired,
            null === $result->skippedReason ? '' : ', skipped: '.$result->skippedReason,
        ));

        return self::SUCCESS;
    }
}
