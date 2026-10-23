<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\CloseLostCashFileImportJobsAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cash:close-lost-import-jobs',
    description: 'Закрывает задания файлового импорта ДДС с потерянным сообщением очереди; без --execute работает read-only',
)]
final class CloseLostCashFileImportJobsCommand extends Command
{
    private const DEFAULT_OLDER_THAN_HOURS = 24;

    public function __construct(
        private readonly CloseLostCashFileImportJobsAction $closeLostJobs,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'UUID компании')
            ->addOption('older-than-hours', null, InputOption::VALUE_REQUIRED, 'Задание в queued дольше стольких часов', (string) self::DEFAULT_OLDER_THAN_HOURS)
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Перевести задания в failed')
            ->addOption('expected-count', null, InputOption::VALUE_REQUIRED, 'Число заданий из последнего dry-run');
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
            $io->error('Для --execute укажите число заданий из dry-run в --expected-count.');

            return Command::INVALID;
        }

        $createdBefore = new \DateTimeImmutable(sprintf('-%d hours', (int) $hours));

        try {
            $result = $execute
                ? $this->entityManager->wrapInTransaction(function () use ($companyId, $createdBefore, $expectedCount): array {
                    $result = ($this->closeLostJobs)($companyId, $createdBefore, true, $expectedCount);
                    $this->entityManager->flush();

                    return $result;
                })
                : ($this->closeLostJobs)($companyId, $createdBefore, false);
        } catch (\DomainException|\RuntimeException $exception) {
            // RuntimeException — очередь Redis недоступна: потерю сообщения не доказать.
            $io->error($exception->getMessage().' Изменений нет.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['mode' => $execute ? 'execute' : 'dry-run'],
            ['created_before' => $createdBefore->format('Y-m-d H:i:s')],
            ['candidates' => $result['candidates']],
            ['closed' => $result['closed']],
        );

        if ($execute) {
            $io->success(sprintf('Заданий закрыто: %d.', $result['closed']));
        } else {
            $io->note('Изменения не применялись. Для выполнения передайте --execute и --expected-count из этого отчёта.');
        }

        return Command::SUCCESS;
    }
}
