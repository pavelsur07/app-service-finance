<?php

declare(strict_types=1);

namespace App\SourceFacts\Command;

use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\SourceFacts\Application\Action\PullSourceFactsAction;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Забор фактов из сервисов (cron каждые 15 минут).
 * Выключено (`SOURCE_FACTS_COMPANIES` пуст) → exit 0; сервис недоступен → WARNING, exit 0;
 * нарушение контракта списка пачек → ERROR, exit 1.
 */
#[AsCommand(name: 'app:source-facts:pull', description: 'Pulls source fact batches from fetcher services and acknowledges applied ones')]
final class SourceFactsPullCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly PullSourceFactsAction $pull,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->pull->isEnabled()) {
            $output->writeln('source-facts: disabled');

            return self::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln('source-facts: another pull is running, skipping');

            return self::SUCCESS;
        }

        try {
            $this->logger->info('Source facts pull started.');
            $result = ($this->pull)();
            $this->logger->info('Source facts pull finished.', (array) $result);
            $output->writeln(sprintf(
                'source-facts: %d batches received, %d applied (%d facts), %d superseded, %d quarantined, %d skipped',
                $result->received,
                $result->applied,
                $result->facts,
                $result->superseded,
                $result->quarantined,
                $result->skipped,
            ));

            return self::SUCCESS;
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('Source facts pull interrupted: service unavailable.', ['error' => $e->getMessage()]);
            $output->writeln('source-facts: service unavailable');

            return self::SUCCESS;
        } catch (ContractViolationException $e) {
            $this->logger->error('Source facts pull rejected: contract violation.', ['error' => $e->getMessage()]);
            $output->writeln('source-facts: contract violation');

            return self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
