<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\AutoApplyCashCategorySuggestionsAction;
use App\Cash\Application\RequestCashAiCategorizationAction;
use App\Cash\Infrastructure\Query\CashAiCategorizationCompaniesQuery;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Webmozart\Assert\Assert;

/**
 * Постановка ИИ-подсказок статей ДДС для операций компании без статьи; перед ней —
 * автоприменение уверенных подсказок, если ворота компании открыты (по умолчанию закрыты:
 * без флага операции не меняются). Выключенный ИИ-сервис для компании — ничего не ставит.
 *
 * `--all-enabled` (cron) — по очереди все компании allowlist с операциями за окно
 * кандидатов; сбой одной компании пишется в лог и не останавливает остальные: после него
 * EntityManager пересоздаётся.
 */
#[AsCommand(
    name: 'app:cash:ai-categorize',
    description: 'Requests AI category suggestions for uncategorized cash transactions of a company',
)]
final class CashAiCategorizeCommand extends Command
{
    public function __construct(
        private readonly AutoApplyCashCategorySuggestionsAction $autoApply,
        private readonly RequestCashAiCategorizationAction $action,
        private readonly CashAiCategorizationCompaniesQuery $companies,
        private readonly AiTaskFacadeInterface $aiTasks,
        private readonly LockFactory $lockFactory,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID')
            ->addOption('all-enabled', null, InputOption::VALUE_NONE, 'All companies enabled for ai-service (cron)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max operations per run and company (1..1000)', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = $input->getOption('company-id');
        $allEnabled = (bool) $input->getOption('all-enabled');
        $limit = (int) $input->getOption('limit');
        try {
            Assert::true(null === $companyId xor !$allEnabled, 'pass exactly one of --company-id or --all-enabled');
            if (!$allEnabled) {
                Assert::uuid($companyId, '--company-id must be a UUID');
            }
            Assert::range($limit, 1, 1000, '--limit must be 1..1000');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        if (!$allEnabled) {
            $this->runCompany((string) $companyId, $limit, $output);

            return self::SUCCESS;
        }

        $since = $this->clock->now()->modify(sprintf('-%d days', RequestCashAiCategorizationAction::LOOKBACK_DAYS));
        $enabled = array_values(array_filter(
            $this->companies->withTransactionsSince($since),
            $this->aiTasks->isEnabledForCompany(...),
        ));
        $failed = 0;
        foreach ($enabled as $id) {
            try {
                $this->runCompany($id, $limit, $output);
            } catch (\Throwable $e) {
                // Сбой одной компании не должен лишать подсказок остальные; повтор — следующий запуск.
                ++$failed;
                $this->logger->error('AI categorization failed for a company.', ['companyId' => $id, 'exception' => $e]);
                // Исключение внутри wrapInTransaction закрывает EntityManager: следующая компания —
                // со свежим (сервисы держат lazy-ссылку, resetManager подменяет её содержимое).
                $manager = $this->managerRegistry->getManager();
                if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                    $this->managerRegistry->resetManager();
                }
                $output->writeln(sprintf('<error>ai-categorize: %s failed: %s</error>', $id, $e->getMessage()));
            }
        }
        $output->writeln(sprintf('ai-categorize: %d enabled companies, %d failed', \count($enabled), $failed));

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }

    private function runCompany(string $companyId, int $limit, OutputInterface $output): void
    {
        $lock = $this->lockFactory->createLock($this->getName().':'.$companyId, 1800);
        if (!$lock->acquire()) {
            $output->writeln(sprintf('ai-categorize: %s: another run for this company is in progress, skipping', $companyId));

            return;
        }

        try {
            // Сначала применить уже полученные уверенные подсказки (ворота компании), затем ставить новые.
            $applied = ($this->autoApply)($companyId);
            $result = ($this->action)($companyId, $limit);
        } finally {
            $lock->release();
        }

        $output->writeln(sprintf('ai-categorize: %s: ', $companyId).(null === $applied->closedReason
            ? sprintf('auto-applied %d, stale %d', $applied->applied, $applied->stale)
            : 'auto-apply closed: '.$applied->closedReason));

        if (!$result->enabled) {
            $output->writeln(sprintf('ai-categorize: %s: ai-service is disabled for the company', $companyId));

            return;
        }
        $output->writeln(sprintf(
            'ai-categorize: %s: %d operations in %d tasks requested, %d tasks re-sent, %d requests expired%s',
            $companyId,
            $result->requested,
            $result->tasks,
            $result->redispatched,
            $result->expired,
            null === $result->skippedReason ? '' : ', skipped: '.$result->skippedReason,
        ));
    }
}
