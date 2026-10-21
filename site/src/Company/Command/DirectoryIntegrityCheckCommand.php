<?php

declare(strict_types=1);

namespace App\Company\Command;

use App\Company\Event\DirectoryIntegrityCheckEvent;
use App\Company\Infrastructure\Repository\CompanyRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Ночная проверка справочников всех компаний, только чтение (cron 07:15): соседние статьи ОПиУ,
 * ДДС и Баланса с одинаковым названием. Находки — exit 1 и один агрегированный `error` со
 * счётчиками (не по статье), чтобы в GlitchTip было одно событие в день. Справочные строки
 * (`info`) на результат не влияют.
 */
#[AsCommand(
    name: 'app:directories:integrity-check',
    description: 'Read-only: дубли названий статей ОПиУ, ДДС и Баланса у соседних статей',
)]
final class DirectoryIntegrityCheckCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $event = new DirectoryIntegrityCheckEvent(array_keys($this->companies->getAllActiveCompanyProfiles()));
        $this->dispatcher->dispatch($event);

        $byDirectory = [];
        $companies = [];
        foreach ($event->duplicates() as $d) {
            $output->writeln(OutputFormatter::escape(\sprintf(
                'duplicate %-8s %s «%s» ×%d%s',
                $d->directory,
                $d->companyId,
                $d->name,
                $d->count,
                null !== $d->parentName ? \sprintf(' в «%s»', $d->parentName) : ' (корень)',
            )));
            $byDirectory[$d->directory] = ($byDirectory[$d->directory] ?? 0) + 1;
            $companies[$d->companyId] = true;
        }
        foreach ($event->notes() as [$directory, $companyId, $text]) {
            $output->writeln(OutputFormatter::escape(\sprintf('info      %-8s %s %s', $directory, $companyId, $text)));
        }

        $total = array_sum($byDirectory);
        ksort($byDirectory);
        $output->writeln(\sprintf('companies checked %d, duplicates %d in %d companies', \count($event->companyIds), $total, \count($companies)));
        if (0 === $total) {
            return Command::SUCCESS;
        }

        $this->logger->error('Directory duplicates found', ['duplicates' => $total, 'companies' => \count($companies), 'byDirectory' => $byDirectory]);

        return Command::FAILURE;
    }
}
