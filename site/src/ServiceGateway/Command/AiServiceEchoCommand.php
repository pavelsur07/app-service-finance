<?php

declare(strict_types=1);

namespace App\ServiceGateway\Command;

use App\ServiceGateway\Application\Service\EchoAiTaskResultConsumer;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Диагностика: ставит задачу `echo` для компании. Результат забирает
 * `app:ai-service:import-results`.
 */
#[AsCommand(
    name: 'app:ai-service:echo',
    description: 'Submits a diagnostic echo task to ai-service for a company',
)]
final class AiServiceEchoCommand extends Command
{
    public function __construct(private readonly AiTaskFacadeInterface $aiTasks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID (must be in AI_SERVICE_COMPANY_ALLOWLIST)')
            ->addOption('text', null, InputOption::VALUE_REQUIRED, 'Text to echo', 'проверка связи');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getOption('company-id');
        if (!Uuid::isValid($companyId)) {
            $output->writeln('<error>--company-id must be a UUID</error>');

            return self::INVALID;
        }

        $taskId = $this->aiTasks->requestTask(
            companyId: $companyId,
            taskType: EchoAiTaskResultConsumer::TASK_TYPE,
            payload: ['text' => (string) $input->getOption('text')],
            idempotencyKey: Uuid::uuid7()->toString(),
        );
        if (null === $taskId) {
            $output->writeln('ai-service: disabled for the company (AI_SERVICE_ENABLED, allowlist, URL or token)');

            return self::SUCCESS;
        }
        $output->writeln(sprintf('ai-service: echo task %s queued (async_sync)', $taskId));

        return self::SUCCESS;
    }
}
