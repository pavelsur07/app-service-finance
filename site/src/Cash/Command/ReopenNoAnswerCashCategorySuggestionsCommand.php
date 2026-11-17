<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Переоткрывает ИИ-подсказки «нет ответа» компании после смены дерева статей ДДС: операции снова
 * попадают в часовой `app:cash:ai-categorize`. Замена дерева ядром делает это сама; команда — для
 * компаний, у которых дерево сменилось раньше. Без --execute только считает.
 */
#[AsCommand(
    name: 'app:cash:ai-suggestions:reopen-no-answer',
    description: 'Переоткрыть ИИ-подсказки «нет ответа» компании после смены дерева ДДС. Без --execute только план.',
)]
final class ReopenNoAnswerCashCategorySuggestionsCommand extends Command
{
    public function __construct(
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Применить; без опции — только план');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = (string) $input->getArgument('companyId');
        Assert::uuid($companyId);

        $count = $this->suggestions->countNoAnswer($companyId);
        if (0 === $count) {
            $io->success('Переоткрывать нечего: подсказок «нет ответа» у компании нет.');

            return Command::SUCCESS;
        }
        if (!$input->getOption('execute')) {
            $io->writeln(sprintf('Будет переоткрыто: %d. ИИ спросит заново на ближайшем часовом запуске.', $count));
            $io->note('Режим просмотра: данные не изменены. Для применения добавьте --execute.');

            return Command::SUCCESS;
        }

        /** @var int $reopened */
        $reopened = $this->entityManager->wrapInTransaction(fn (): int => $this->suggestions->reopenNoAnswer($companyId, 'reopened', $this->clock->now()));
        $this->logger->info('Cash AI no-answer suggestions reopened', ['companyId' => $companyId, 'count' => $reopened]);
        $io->success(sprintf('Переоткрыто: %d. ИИ спросит заново на ближайшем часовом запуске.', $reopened));

        return Command::SUCCESS;
    }
}
