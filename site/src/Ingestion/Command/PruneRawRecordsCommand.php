<?php

declare(strict_types=1);

namespace App\Ingestion\Command;

use App\Ingestion\Application\Action\PruneRawRecordsAction;
use App\Ingestion\Application\Command\PruneRawRecordsCommand as PruneRawRecordsApplicationCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ingestion:raw:prune',
    description: 'Deletes stored payloads of raw records past the retention window, keeping their metadata rows.',
)]
final class PruneRawRecordsCommand extends Command
{
    use LockableTrait;

    /** Окно горячего хранения сырья — год. */
    private const DEFAULT_OLDER_THAN_DAYS = 365;

    private const DEFAULT_LIMIT = 500;

    public function __construct(private readonly PruneRawRecordsAction $action)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Retention window in days.', self::DEFAULT_OLDER_THAN_DAYS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum raw records handled in one run.', self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report which payloads would be deleted without touching anything.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete stored payloads while keeping raw-record metadata.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Удаление необратимо, и два прогона одновременно удаляли бы одни и те
        // же объекты, мешая друг другу отчитаться.
        if (!$this->lock()) {
            $io->warning('Command is already running in another process.');

            return Command::SUCCESS;
        }

        try {
            $command = new PruneRawRecordsApplicationCommand(
                olderThanDays: $this->positiveInt($input, 'older-than-days', 3650),
                limit: $this->positiveInt($input, 'limit', 1000),
                execute: $this->mode($input),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            $this->release();

            return Command::INVALID;
        }

        try {
            $result = ($this->action)($command);
        } finally {
            $this->release();
        }

        $io->table(['setting', 'value'], [
            ['mode', $command->execute ? 'execute' : 'dry-run'],
            ['olderThanDays', (string) $command->olderThanDays],
            ['candidates', (string) $result->candidates],
            ['candidateBytes', (string) $result->candidateBytes],
            // Незавершённое прошлых прогонов обслуживается ПЕРВЫМ и из того же
            // лимита. Без этих двух строк dry-run умалчивал бы о работе,
            // которую execute сделает раньше всего остального.
            ['pendingRetries', (string) $result->pendingRetries],
            ['pendingBytes', (string) $result->pendingBytes],
            ['prunedPayloads', (string) $result->prunedPayloads],
            ['bytesFreed', (string) $result->bytesFreed],
            ['heldByIssues', (string) $result->heldByIssues],
            ['heldAfterPlanning', (string) $result->heldAfterPlanning],
            ['orphanedObjects', (string) $result->orphanedObjects],
        ]);

        // Осиротевший объект ничего не ломает, но место занимает, и убрать его
        // может только человек по пути из лога. Ненулевой код возврата делает
        // это видимым в выводе cron, а не только в логах.
        if ($result->orphanedObjects > 0) {
            $io->warning(sprintf('%d object(s) were left in storage; see the log for their paths.', $result->orphanedObjects));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Pruned payloads of %d raw record(s), freed %d byte(s).',
            $result->prunedPayloads,
            $result->bytesFreed,
        ));

        return Command::SUCCESS;
    }

    /**
     * Ровно одно действие. Умолчания у необратимой команды нет: и «удалил, а
     * не просили», и «не удалил, а ждали» — одинаково плохие сюрпризы.
     */
    private function mode(InputInterface $input): bool
    {
        $modes = array_values(array_filter([
            (bool) $input->getOption('dry-run') ? 'dry-run' : null,
            (bool) $input->getOption('execute') ? 'execute' : null,
        ]));

        if (1 !== count($modes)) {
            throw new \InvalidArgumentException('Choose exactly one action: --dry-run or --execute.');
        }

        return 'execute' === $modes[0];
    }

    /**
     * Строгий разбор: «0», «abc» и «-1» обязаны быть ошибкой ввода, а не молча
     * превращаться в ноль и делать прогон пустым.
     */
    private function positiveInt(InputInterface $input, string $option, int $max): int
    {
        $value = (string) $input->getOption($option);
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from 1 to %d.', $option, $max));
        }

        $parsed = (int) $value;
        if ($parsed < 1 || $parsed > $max) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be an integer from 1 to %d.', $option, $max));
        }

        return $parsed;
    }
}
