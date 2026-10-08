<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Performance;

/**
 * Читает суточные файлы `performance-YYYY-MM-DD.jsonl` (RotatingFileHandler) за период.
 *
 * Ограничения против неконтролируемого сканирования: только файлы с датой в имени из
 * периода (не glob всего каталога с чтением), потолок байт и событий — по достижении
 * чтение останавливается и отчёт помечается `truncated`. Построчное чтение, файл
 * целиком в память не грузится.
 */
final class PerformanceLogReader
{
    public const FILE_PREFIX = 'performance-';
    public const FILE_SUFFIX = '.jsonl';

    public int $filesRead = 0;
    public int $filesMissing = 0;
    public int $bytesRead = 0;
    public int $linesRead = 0;
    public int $invalidLines = 0;
    public int $foreignLines = 0;
    public bool $truncated = false;

    public function __construct(
        private readonly string $logDir,
        private readonly int $maxBytes,
        private readonly int $maxEvents,
    ) {
    }

    /**
     * @return \Generator<int, array<string, mixed>> контекст события (поля `v`, `stage`, ...)
     */
    public function read(\DateTimeImmutable $from, \DateTimeImmutable $to): \Generator
    {
        $events = 0;

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $path = rtrim($this->logDir, '/').'/'.self::FILE_PREFIX.$day->format('Y-m-d').self::FILE_SUFFIX;
            if (!is_file($path)) {
                ++$this->filesMissing;
                continue;
            }

            $handle = @fopen($path, 'r');
            if (false === $handle) {
                ++$this->filesMissing;
                continue;
            }

            ++$this->filesRead;

            try {
                while (false !== ($line = fgets($handle))) {
                    $this->bytesRead += \strlen($line);
                    ++$this->linesRead;

                    if ($this->bytesRead > $this->maxBytes || $events >= $this->maxEvents) {
                        $this->truncated = true;

                        return;
                    }

                    $event = $this->decode($line);
                    if (null === $event) {
                        continue;
                    }

                    ++$events;

                    yield $event;
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array
    {
        if ('' === trim($line)) {
            return null;
        }

        try {
            $record = json_decode($line, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            ++$this->invalidLines;

            return null;
        }

        $context = \is_array($record) ? ($record['context'] ?? null) : null;
        if (!\is_array($context) || PerformanceRecorder::LOG_MESSAGE !== ($record['message'] ?? null)) {
            ++$this->foreignLines;

            return null;
        }

        if (PerformanceRecorder::SCHEMA_VERSION !== ($context['v'] ?? null) || !\is_string($context['stage'] ?? null)) {
            ++$this->foreignLines;

            return null;
        }

        return $context;
    }
}
