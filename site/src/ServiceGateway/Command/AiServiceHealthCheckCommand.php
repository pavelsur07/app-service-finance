<?php

declare(strict_types=1);

namespace App\ServiceGateway\Command;

use App\ServiceGateway\Application\Service\AiBudget;
use App\ServiceGateway\Application\Service\AiRunJournal;
use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Infrastructure\Query\AiUsageQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Гейт «тишины» ИИ (cron раз в час, docs/workflow/health-gates.md). Только чтение, кроме
 * чистки журнала запусков старше 90 дней.
 *
 * - error: подключённая компания без запуска `ai-categorize` дольше SILENCE_HOURS
 *   (от последнего запуска или от подключения, если запусков не было);
 * - error: за сутки ≥ MIN_RESULTS результатов и доля `failed` ≥ FAILED_SHARE;
 * - warning (не красное): исчерпан лимит расходов компании или общий — ИИ стоит штатно до
 *   нового месяца или повышения лимита в админке. Запуски такой компании идут (`budget_exceeded`).
 *
 * Сервис выключен или ни одной компании — exit 0 без сообщений. exit 1 — штатный результат гейта.
 */
#[AsCommand(name: 'app:ai-service:health-check', description: 'AI health gate: silent cron, failing results')]
final class AiServiceHealthCheckCommand extends Command
{
    public const SILENCE_HOURS = 3;
    public const MIN_RESULTS = 10;
    public const FAILED_SHARE = 0.5;

    public function __construct(
        private readonly AiServiceAccess $access,
        private readonly AiRunJournal $runs,
        private readonly AiUsageQuery $usage,
        private readonly AiBudget $budget,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = $this->runs->purgeOld();
        if (!$this->access->isEnabled()) {
            $output->writeln('ai health: service disabled');

            return self::SUCCESS;
        }
        $companies = $this->access->grantedCompanies();
        if ([] === $companies) {
            $output->writeln('ai health: no companies connected');

            return self::SUCCESS;
        }

        $now = $this->clock->now();
        $silenceFrom = $now->modify(sprintf('-%d hours', self::SILENCE_HOURS));
        $lastRuns = $this->runs->lastRunAt(array_keys($companies));
        $silent = [];
        foreach ($companies as $companyId => $enabledAt) {
            $last = max($lastRuns[$companyId] ?? $enabledAt, $enabledAt);
            if ($last < $silenceFrom) {
                $silent[] = $companyId;
            }
        }

        $exhausted = [];
        // ponytail: запрос лимита на компанию; одним запросом — когда подключённых станут сотни.
        foreach (array_keys($companies) as $companyId) {
            if ($this->budget->forCompany($companyId)->exceeded()) {
                $exhausted[] = $companyId;
            }
        }
        if ([] !== $exhausted) {
            $this->logger->warning('AI health: monthly AI budget is exhausted.', ['companyIds' => $exhausted]);
        }

        $results = $this->usage->resultsSince($now->modify('-24 hours'));
        $failing = $results['total'] >= self::MIN_RESULTS && $results['failed'] / $results['total'] >= self::FAILED_SHARE;

        if ([] !== $silent) {
            $this->logger->error('AI health: connected companies without an AI run.', [
                'companyIds' => $silent,
                'silenceHours' => self::SILENCE_HOURS,
            ]);
        }
        if ($failing) {
            $this->logger->error('AI health: most AI results failed in the last 24 hours.', $results);
        }
        $output->writeln(sprintf(
            'ai health: %d companies, %d silent, %d over budget, results 24h %d (failed %d), purged runs %d',
            \count($companies),
            \count($silent),
            \count($exhausted),
            $results['total'],
            $results['failed'],
            $purged,
        ));

        return [] === $silent && !$failing ? self::SUCCESS : self::FAILURE;
    }
}
