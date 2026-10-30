<?php

declare(strict_types=1);

namespace App\Cash\Command;

use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\DTO\CashCategorizationHistoryCase;
use App\Cash\Application\Service\CashCategorizationPayloadMapper;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

/**
 * Read-only: история ручных категоризаций компании для золотого набора evals
 * (docs/tasks/ai/stages/stage-2.md, 2.2). JSON `cash-categorize-history/v1` — в stdout, сводка —
 * в stderr. Выгрузка содержит назначения и контрагентов в открытом виде: в репозиторий она
 * попадает только через `python -m ai_service evals-import` (обезличивание).
 */
#[AsCommand(
    name: 'app:cash:ai-eval-export',
    description: 'Exports manually categorized cash operations of a company as an AI eval set (read-only, JSON to stdout)',
)]
final class CashAiEvalExportCommand extends Command
{
    public const FORMAT = 'cash-categorize-history/v1';

    public function __construct(
        private readonly CashCategorizationQuery $query,
        private readonly CashCategorizationPayloadMapper $mapper,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company UUID')
            ->addOption('per-category', null, InputOption::VALUE_REQUIRED, 'Latest operations per category', '20')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max operations', '400');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $companyId = (string) $input->getOption('company-id');
        $perCategory = filter_var($input->getOption('per-category'), \FILTER_VALIDATE_INT);
        $limit = filter_var($input->getOption('limit'), \FILTER_VALIDATE_INT);
        try {
            Assert::uuid($companyId, '--company-id must be a UUID');
            Assert::integer($perCategory, '--per-category must be an integer');
            Assert::range($perCategory, 1, 1000, '--per-category must be between 1 and 1000');
            Assert::integer($limit, '--limit must be an integer');
            Assert::range($limit, 1, 10000, '--limit must be between 1 and 10000');
        } catch (\InvalidArgumentException $e) {
            $errors->writeln('<error>'.$e->getMessage().'</error>');

            return self::INVALID;
        }

        $categories = $this->query->findLeafCategories($companyId);
        $leaf = array_flip(array_map(static fn (CashCategorizationCategory $c): string => $c->id, $categories));
        $history = $this->query->findManuallyCategorized($companyId, $perCategory, $limit);
        // Статья, которая с тех пор стала группой, отключена или удалена, в справочнике модели не будет.
        $cases = array_values(array_filter(
            $history,
            fn (CashCategorizationHistoryCase $case): bool => isset($leaf[$case->categoryId]) && $this->mapper->isRepresentable($case->operation),
        ));

        $output->writeln(json_encode([
            'format' => self::FORMAT,
            'exported_at' => $this->clock->now()->format(\DATE_ATOM),
            'categories' => array_map($this->mapper->category(...), $categories),
            'cases' => array_map(fn (CashCategorizationHistoryCase $case): array => [
                'operation' => $this->mapper->operation($case->operation),
                'expected_category_id' => $case->categoryId,
            ], $cases),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        $errors->writeln(sprintf(
            'ai-eval-export: %d cases over %d categories (%d skipped: category no longer a leaf or amount out of contract)',
            \count($cases),
            \count(array_unique(array_map(static fn (CashCategorizationHistoryCase $c): string => $c->categoryId, $cases))),
            \count($history) - \count($cases),
        ));

        return self::SUCCESS;
    }
}
