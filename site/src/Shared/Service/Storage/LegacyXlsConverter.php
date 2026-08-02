<?php

declare(strict_types=1);

namespace App\Shared\Service\Storage;

use App\Shared\Command\ConvertLegacyXlsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Приводит устаревший бинарный .xls к .xlsx, который умеют читать все импорты.
 *
 * OpenSpout поддерживает XLSX, CSV и ODS — ридера для .xls у него нет и никогда не было.
 * Четыре импорта в проекте выбирали ридер по расширению и на `.xls` обращались к
 * несуществующему классу: файл принимался, задача уходила в очередь и молча умирала там
 * с фатальной ошибкой, а пользователь видел, что импорт «принят».
 *
 * Конвертация, а не собственный ридер: так весь код обхода строк остаётся общим для
 * обоих форматов, и поддерживать приходится один путь вместо двух.
 *
 * Сама конвертация выполняется в дочернем процессе. Это не осторожность впрок:
 * PhpSpreadsheet на обрезанном .xls с валидной сигнатурой (обычный результат
 * прерванной загрузки) запрашивает сотни мегабайт прямо при разборе OLE-заголовка
 * и убивает процесс фатальной ошибкой. Перехватить её нельзя, поэтому любой предел
 * внутри того же процесса — фикция: воркер умирал бы молча ровно так же, как до
 * этой правки. В отдельном процессе умирает только он, а импорт получает причину.
 */
final readonly class LegacyXlsConverter
{
    /**
     * Потолок размера исходника.
     *
     * Отсекает совсем аномальные загрузки до запуска дочернего процесса. Настоящей
     * защитой от нехватки памяти не является: замер показывает, что .xls в 0,7 МБ
     * разворачивается в 55 МБ объектов, то есть размер файла памяти не предсказывает.
     */
    public const DEFAULT_MAX_SOURCE_BYTES = 20 * 1024 * 1024;

    /**
     * Цена одной ячейки на пике конвертации.
     *
     * Замер, а не оценка: плотный числовой лист 2000×20 (40 000 ячеек) даёт пик
     * 55 МБ, то есть ~750 байт на ячейку — считая и загрузку книги, и работу
     * XlsxWriter. Берём вдвое больше: строки и стили тяжелее чисел, а платить
     * за ошибку в меньшую сторону приходится отказом в импорте.
     *
     * Перемерять командой из docs/tasks/fix-xls-import/memory-measurement.md,
     * если обновится PhpSpreadsheet.
     */
    public const BYTES_PER_CELL = 1536;

    /**
     * Доля memory_limit родителя, которую отдаём дочернему процессу.
     *
     * Половина, потому что родитель продолжает жить: EntityManager, уже прочитанные
     * строки импорта, сам Symfony никуда не деваются, пока идёт конвертация.
     */
    private const MEMORY_BUDGET_RATIO = 0.5;

    /**
     * Запасной бюджет, когда memory_limit родителя не ограничен (-1).
     */
    private const FALLBACK_MEMORY_BUDGET_BYTES = 256 * 1024 * 1024;

    /**
     * Потолок времени конвертации.
     *
     * Повреждённый файл способен не только съесть память, но и увести разбор в
     * длинный цикл. Без предела задача висела бы в очереди вместо отказа.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /**
     * Нижняя граница памяти дочернего процесса.
     *
     * Дочерний процесс поднимает ядро Symfony, и с бюджетом в несколько мегабайт
     * он не дожил бы до конвертации. Потолок по ячейкам задаётся отдельно, поэтому
     * этот пол ничего не ослабляет.
     */
    private const MIN_CHILD_MEMORY_BYTES = 128 * 1024 * 1024;

    private int $childMemoryBytes;

    private int $maxCells;

    public function __construct(
        private TemporaryFileFactory $temporaryFiles,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        // Параметрами, а не константами: иначе проверить отказ можно было бы только
        // двадцатимегабайтным файлом в тесте.
        private int $maxSourceBytes = self::DEFAULT_MAX_SOURCE_BYTES,
        ?int $memoryBudgetBytes = null,
        ?int $maxCells = null,
        private int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $budget = $memoryBudgetBytes ?? self::defaultMemoryBudgetBytes();
        $this->childMemoryBytes = max($budget, self::MIN_CHILD_MEMORY_BYTES);
        $this->maxCells = $maxCells ?? self::maxCellsForBudget($budget);
    }

    /**
     * Бюджет памяти дочернего процесса, выведенный из memory_limit родителя.
     *
     * Фиксированное число здесь было бы фикцией: один и тот же лист безопасен при
     * memory_limit=1G и убивает процесс при 256M.
     */
    public static function defaultMemoryBudgetBytes(): int
    {
        $limit = self::memoryLimitBytes();

        return $limit > 0
            ? (int) ($limit * self::MEMORY_BUDGET_RATIO)
            : self::FALLBACK_MEMORY_BUDGET_BYTES;
    }

    /**
     * Сколько ячеек помещается в заданный бюджет памяти.
     *
     * Отдельным методом и без чтения ini: так расчёт проверяется тестом, не трогая
     * глобальную настройку процесса.
     */
    public static function maxCellsForBudget(int $budgetBytes): int
    {
        return intdiv($budgetBytes, self::BYTES_PER_CELL);
    }

    /**
     * Отдаёт путь, пригодный для чтения OpenSpout: для `.xls` — путь сконвертированной
     * копии, для остальных форматов — исходный путь без изменений.
     *
     * @template T
     *
     * @param callable(string $readablePath): T $consumer
     * @param string|null $formatHint расширение, если путь его не несёт
     *
     * @return T
     */
    public function withReadablePath(string $path, callable $consumer, ?string $formatHint = null): mixed
    {
        // Формат обычно виден по пути, но не всегда: загруженный файл может лежать
        // на диске без расширения, а его настоящий формат знает только имя оригинала.
        $format = strtolower($formatHint ?? pathinfo($path, \PATHINFO_EXTENSION));

        if ('xls' !== $format) {
            return $consumer($path);
        }

        $size = @filesize($path);
        if (false === $size) {
            // Размер неизвестен — значит и файла, скорее всего, нет. Запускать ради
            // него процесс незачем.
            throw new LegacyXlsUnreadableException($path);
        }

        if ($size > $this->maxSourceBytes) {
            throw new LegacyXlsTooLargeException($size, $this->maxSourceBytes);
        }

        return $this->temporaryFiles->withXlsxPath(function (string $convertedPath) use ($path, $consumer): mixed {
            $this->convert($path, $convertedPath);

            return $consumer($convertedPath);
        });
    }

    private function convert(string $source, string $destination): void
    {
        $php = (new PhpExecutableFinder())->find(false);
        if (false === $php) {
            throw new ObjectStorageException('PHP executable not found for xls conversion.');
        }

        $process = new Process(
            [
                $php,
                // Жёсткий потолок дочернего процесса: даже если предел по ячейкам
                // не сработал (повреждённый заголовок врёт о размере листа),
                // процесс умрёт здесь, а не унесёт с собой воркер.
                '-d', 'memory_limit='.$this->childMemoryBytes,
                $this->projectDir.'/bin/console',
                ConvertLegacyXlsCommand::NAME,
                $source,
                $destination,
                '--max-cells='.$this->maxCells,
            ],
            $this->projectDir,
            timeout: $this->timeoutSeconds,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException|ProcessSignaledException $e) {
            // Убитый по сигналу процесс не доходит до кода возврата: Process бросает
            // исключение прямо из wait(). Без этой ветки OOM-killer снова превращал бы
            // отказ в техническое исключение вместо понятной причины.
            throw new LegacyXlsUnreadableException($source, $e);
        }

        if ($process->isSuccessful()) {
            return;
        }

        $reportedCells = trim($process->getOutput());

        // Код сверяется вместе с содержимым stdout: если числа нет, значит команда
        // завершилась не там, где мы думаем, и выдавать «0 ячеек при пределе» —
        // значит подменять настоящую причину выдуманной.
        if (ConvertLegacyXlsCommand::EXIT_TOO_MANY_CELLS === $process->getExitCode()
            && 1 === preg_match('/^[1-9]\\d*$/', $reportedCells)
        ) {
            throw new LegacyXlsTooManyCellsException((int) $reportedCells, $this->maxCells);
        }

        // Любой другой ненулевой код — в том числе смерть по нехватке памяти —
        // означает, что прочитать файл не удалось.
        throw new LegacyXlsUnreadableException($source, self::technicalCause($process));
    }

    /**
     * Причина смерти дочернего процесса, пригодная для разбора в логах.
     *
     * Пользователю она не показывается: в сообщении исключения остаётся понятный
     * текст, а технические подробности — включая текст фатальной ошибки PHP при
     * нехватке памяти — уходят в previous и доезжают до Sentry.
     */
    private static function technicalCause(Process $process): ?\Throwable
    {
        $stderr = trim($process->getErrorOutput());
        if ('' === $stderr) {
            $stderr = sprintf('Дочерний процесс конвертации завершился с кодом %d.', (int) $process->getExitCode());
        }

        return new \RuntimeException($stderr);
    }

    /**
     * @return int байты, либо -1 если предел не задан
     */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ('' === $raw || '-1' === $raw) {
            return -1;
        }

        $value = (int) $raw;
        $multiplier = match (strtolower(substr($raw, -1))) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };

        return $value * $multiplier;
    }
}
