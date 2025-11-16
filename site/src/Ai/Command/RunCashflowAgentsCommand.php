<?php

declare(strict_types=1);

namespace App\Ai\Command;

use App\Ai\Enum\AiAgentType;
use App\Ai\Repository\AiAgentRepository;
use App\Ai\Repository\AiRunRepository;
use App\Ai\Repository\AiSuggestionRepository;
use App\Ai\Service\AiAgentRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:ai:run-cashflow-agents', description: 'Запускает cashflow AI-агентов для активных компаний')]
final class RunCashflowAgentsCommand extends Command
{
    public function __construct(
        private readonly AiAgentRepository $agentRepository,
        private readonly AiRunRepository $runRepository,
        private readonly AiSuggestionRepository $suggestionRepository,
        private readonly AiAgentRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->agentRepository->findEnabledByType(AiAgentType::CASHFLOW);
        if (0 === \count($agents)) {
            $output->writeln('<info>Активных cashflow-агентов не найдено.</info>');

            return Command::SUCCESS;
        }

        foreach ($agents as $agent) {
            $company = $agent->getCompany();
            $output->writeln(sprintf('→ Компания %s (%s): запуск агента', $company->getName(), $company->getId()));

            $this->runner->runAgent($agent);

            $lastRun = $this->runRepository->findLatestForAgent($agent);
            $suggestionsCount = $lastRun ? $this->suggestionRepository->countForRun($lastRun) : 0;
            $status = $lastRun?->getStatus()->name ?? 'UNKNOWN';

            $output->writeln(sprintf('   Статус: %s, рекомендации: %d', $status, $suggestionsCount));
        }

        return Command::SUCCESS;
    }
}
