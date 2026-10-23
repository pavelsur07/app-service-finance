<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\CloseOpenImportLogsAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash:close-open-import-logs',
    description: 'Закрывает журналы импорта ДДС, оставшиеся без времени завершения; без --execute работает read-only',
)]
final class CloseOpenImportLogsCommand extends Command
{
    private const DEFAULT_OLDER_THAN_HOURS = 24;

    public function __construct(
        private readonly CloseOpenImportLogsAction $closeOpenLogs,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('older-than-hours', null, InputOption::VALUE_REQUIRED, 'Журнал открыт дольше стольких часов', (string) self::DEFAULT_OLDER_THAN_HOURS)
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Проставить время завершения')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число журналов из последнего dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = $input->getOption('company');
        $hours = $input->getOption('older-than-hours');
        $execute = true === $input->getOption('execute');
        $expectedCount = $input->getOption('expected-count');
        $expectedCount = is_string($expectedCount) && ctype_digit($expectedCount) ? (int) $expectedCount : null;

        if (!is_string($companyId) || !Uuid::isValid($companyId)) {
            $io->error('Укажите UUID компании в --company.');

            return Command::INVALID;
        }
        if (!is_string($hours) || !ctype_digit($hours) || (int) $hours < 1) {
            $io->error('--older-than-hours — целое число часов, не меньше 1.');

            return Command::INVALID;
        }
        if ($execute && null === $expectedCount) {
            $io->error('Для --execute укажите число журналов из dry-run в --expected-count.');

            return Command::INVALID;
        }

        $startedBefore = new \DateTimeImmutable(sprintf('-%d hours', (int) $hours), new \DateTimeZone('UTC'));

        try {
            $result = $execute
                ? $this->entityManager->wrapInTransaction(function () use ($companyId, $startedBefore, $expectedCount): array {
                    $result = ($this->closeOpenLogs)($companyId, $startedBefore, true, $expectedCount);
                    $this->entityManager->flush();

                    return $result;
                })
                : ($this->closeOpenLogs)($companyId, $startedBefore, false);
        } catch (\DomainException $exception) {
            $io->error($exception->getMessage().' Изменений нет.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['mode' => $execute ? 'execute' : 'dry-run'],
            ['started_before_utc' => $startedBefore->format('Y-m-d H:i:s')],
            ['candidates' => $result['candidates']],
            ['closed' => $result['closed']],
        );

        if ($execute) {
            $io->success(sprintf('Журналов закрыто: %d.', $result['closed']));
        } else {
            $io->note('Изменения не применялись. Для выполнения передайте --execute и --expected-count из этого отчёта.');
        }

        return Command::SUCCESS;
    }
}
