<?php

declare(strict_types=1);

namespace App\Shared\Command;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Конвертирует .xls в .xlsx в отдельном процессе.
 *
 * Команда служебная и вызывается не человеком, а LegacyXlsConverter. Вынесена она
 * не ради удобства, а потому что PhpSpreadsheet на повреждённом .xls запрашивает
 * произвольный объём памяти прямо при разборе OLE-заголовка и убивает процесс
 * фатальной ошибкой. Фатальную ошибку нельзя перехватить: любой try/catch внутри
 * того же процесса бесполезен, и воркер умирал бы молча — ровно тот дефект, ради
 * которого писался конвертер. Здесь умирает дочерний процесс, а родитель читает
 * код возврата и превращает его во внятный отказ.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Служебная конвертация .xls в .xlsx в изолированном процессе',
    hidden: true,
)]
final class ConvertLegacyXlsCommand extends Command
{
    public const NAME = 'app:xls:convert';

    /**
     * Лист не помещается в отведённый бюджет памяти.
     *
     * Коды начинаются с 4: 1 и 2 заняты Command::FAILURE и Command::INVALID, и на
     * них же завершается консоль при ошибке разбора аргументов. Совпадение читалось
     * бы как «лист слишком большой» там, где команду просто неверно вызвали.
     */
    public const EXIT_TOO_MANY_CELLS = 4;

    /**
     * Файл не читается: повреждён, обрезан или это не .xls.
     */
    public const EXIT_UNREADABLE = 5;

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Путь к исходному .xls')
            ->addArgument('destination', InputArgument::REQUIRED, 'Путь, куда записать .xlsx')
            ->addOption('max-cells', null, InputOption::VALUE_REQUIRED, 'Потолок числа ячеек первого листа');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string) $input->getArgument('source');
        $destination = (string) $input->getArgument('destination');
        $maxCells = (int) $input->getOption('max-cells');

        $reader = IOFactory::createReader('Xls');

        // Разбор заголовка уже небезопасен: он и есть место, где повреждённый файл
        // запрашивает лишнюю память. Поэтому в дочернем процессе находится всё,
        // включая чтение метаданных.
        try {
            $sheets = $reader->listWorksheetInfo($source);
        } catch (\Throwable $e) {
            self::reportCause($output, $e->getMessage());

            return self::EXIT_UNREADABLE;
        }

        if ([] === $sheets) {
            self::reportCause($output, 'Файл .xls не содержит листов.');

            return self::EXIT_UNREADABLE;
        }

        $first = $sheets[0];
        $cells = (int) $first['totalRows'] * (int) $first['totalColumns'];
        if ($maxCells > 0 && $cells > $maxCells) {
            $output->writeln((string) $cells, OutputInterface::VERBOSITY_QUIET);

            return self::EXIT_TOO_MANY_CELLS;
        }

        // Потребители читают только первый лист и прерывают обход, поэтому остальные
        // листы грузить незачем — это прямая экономия памяти, а не оптимизация впрок.
        $reader->setLoadSheetsOnly((string) $first['worksheetName']);
        $reader->setReadEmptyCells(false);

        try {
            $spreadsheet = $reader->load($source);
        } catch (\Throwable $e) {
            self::reportCause($output, $e->getMessage());

            return self::EXIT_UNREADABLE;
        }

        try {
            (new XlsxWriter($spreadsheet))->save($destination);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return Command::SUCCESS;
    }

    /**
     * Техническая причина уходит в stderr, а не в stdout: в stdout родитель читает
     * число ячеек, и смешивать их значило бы разбирать текст вместо кода возврата.
     */
    private static function reportCause(OutputInterface $output, string $cause): void
    {
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stream->writeln($cause, OutputInterface::VERBOSITY_QUIET);
    }
}
