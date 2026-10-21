<?php

declare(strict_types=1);

namespace App\Finance\Command;

use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Action\ApplyDefaultPLTemplateAction;
use App\Finance\Enum\PLTemplateApplicationSource;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Добавляет компании недостающие статьи базового шаблона ОПиУ — тот же перенос,
 * что кнопка «Базовый шаблон ОПиУ» на экране импорта категорий. Существующие
 * статьи не меняются. Без --execute — только план.
 */
#[AsCommand(
    name: 'app:finance:pl-tree:apply-default',
    description: 'Добавляет компании недостающие статьи базового шаблона ОПиУ (без --execute — только план).',
)]
final class ApplyDefaultPLTreeCommand extends Command
{
    public function __construct(
        private readonly ApplyDefaultPLTemplateAction $applyTemplate,
        private readonly CompanyFacade $companies,
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
        $companyId = (string) $input->getArgument('companyId');
        $execute = (bool) $input->getOption('execute');
        if (!Uuid::isValid($companyId)) {
            $output->writeln(\sprintf('<error>Не UUID: %s</error>', $companyId));

            return Command::INVALID;
        }

        try {
            // Шаблон профиля бизнеса компании; неизвестная компания — тот же отказ, что у импорта.
            if (null === $this->companies->findById($companyId)) {
                throw new \DomainException('Целевая компания не найдена.');
            }
            $result = ($this->applyTemplate)($companyId, !$execute, PLTemplateApplicationSource::COMMAND);
        } catch (\DomainException $e) {
            $this->logger->warning('Default P&L tree not applied', ['companyId' => $companyId, 'reason' => $e->getMessage()]);
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        foreach ($result->created as $row) {
            $output->writeln(\sprintf('create  %-40s %s', $row->code ?? '', $row->path));
        }
        foreach ($result->similar as $row) {
            $output->writeln(\sprintf('similar %-40s %s ≈ «%s»', $row->code ?? '', $row->path, $row->existingName));
        }
        foreach ($result->skippedWithSimilar as $row) {
            $output->writeln(\sprintf('skip    %-40s %s', $row->code ?? '', $row->path));
        }
        $output->writeln(\sprintf(
            '%s: create %d, keep existing %d, similar %d; company roots recognized %d of %d',
            $execute ? 'applied' : 'plan (dry run)',
            \count($result->created),
            $result->unchangedCount,
            \count($result->similar),
            $result->recognizedRoots,
            $result->companyRoots,
        ));
        if ([] !== $result->unresolvedFormulaCodes) {
            $output->writeln(\sprintf('<comment>formula codes without a category: %s</comment>', implode(', ', $result->unresolvedFormulaCodes)));
        }

        return Command::SUCCESS;
    }
}
