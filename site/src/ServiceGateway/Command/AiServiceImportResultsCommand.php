<?php

declare(strict_types=1);

namespace App\ServiceGateway\Command;

use App\ServiceGateway\Application\Action\ImportAiResultsAction;
use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Забор готовых результатов ai-service и раздача потребителям (для cron).
 * Выключено → INFO, exit 0; недоступен → WARNING, exit 0; нарушение контракта → ERROR, exit 1.
 */
#[AsCommand(
    name: 'app:ai-service:import-results',
    description: 'Pulls finished ai-service task results, hands them to consumers and acknowledges them',
)]
final class AiServiceImportResultsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly AiServiceAccess $access,
        private readonly ImportAiResultsAction $import,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->access->isEnabled()) {
            $this->logger->info('AI results import is disabled.');
            $output->writeln('ai-service: disabled');

            return self::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln('ai-service: another import is running, skipping');

            return self::SUCCESS;
        }

        try {
            $this->logger->info('AI results import started.');
            $result = ($this->import)();
            $this->logger->info('AI results import finished.', [
                'received' => $result->received,
                'acked' => $result->acked,
                'unhandled' => $result->unhandled,
                'failed' => $result->failed,
                'invalid' => $result->invalid,
            ]);
            $output->writeln(sprintf(
                'ai-service: %d received, %d acked, %d without consumer, %d consumer failures, %d invalid',
                $result->received,
                $result->acked,
                $result->unhandled,
                $result->failed,
                $result->invalid,
            ));

            return self::SUCCESS;
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('AI results import skipped: ai-service unavailable.', ['error' => $e->getMessage()]);
            $output->writeln('ai-service: unavailable');

            return self::SUCCESS;
        } catch (ContractViolationException $e) {
            $this->logger->error('AI results import rejected: contract violation.', ['error' => $e->getMessage()]);
            $output->writeln('ai-service: contract violation');

            return self::FAILURE;
        } finally {
            $this->release();
        }
    }
}
