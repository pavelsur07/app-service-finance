<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\DTO\CounterpartyDisplayNameRefreshResult;
use App\Company\Domain\Service\CounterpartyDisplayNameFormatter;
use App\Company\Infrastructure\Persistence\CounterpartyDisplayNameWriter;
use App\Company\Infrastructure\Query\CounterpartyDisplayNameBacklogQuery;
use Psr\Log\LoggerInterface;

/**
 * Пересчёт отображаемого названия контрагентов правилами (docs/plan/counterparty-display-name.md).
 *
 * Сырое name и updatedAt не меняются, импорт не участвует. Обрабатываются только строки без
 * авто-названия текущей версии правил; ручные — никогда. Запись — условным UPDATE: параллельная
 * правка пользователя не перезаписывается. Идемпотентно: повторный прогон ничего не находит.
 * Скалярные порции по id — память не растёт, EntityManager не задействован.
 */
final class RefreshCounterpartyDisplayNamesAction
{
    private const BATCH_SIZE = 200;
    private const MAX_LENGTH = 255;

    public function __construct(
        private readonly CounterpartyDisplayNameBacklogQuery $backlog,
        private readonly CounterpartyDisplayNameWriter $writer,
        private readonly CounterpartyDisplayNameFormatter $formatter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(bool $dryRun, int $sampleLimit = 50): CounterpartyDisplayNameRefreshResult
    {
        $version = CounterpartyDisplayNameFormatter::RULES_VERSION;
        $result = new CounterpartyDisplayNameRefreshResult();

        $this->logger->info('Counterparty display name refresh started.', ['dryRun' => $dryRun, 'rulesVersion' => $version]);

        $afterId = null;
        do {
            $batch = $this->backlog->fetch($version, $afterId, self::BATCH_SIZE);

            foreach ($batch as $row) {
                ++$result->processed;
                $afterId = $row['id'];

                $after = $this->formatter->format($row['name']);
                // Результат длиннее колонки (ОПФ и «ёлочки» к названию в 255 символов) — не уверены.
                if (null !== $after && mb_strlen($after) > self::MAX_LENGTH) {
                    $after = null;
                }

                if (!$dryRun && !$this->writer->writeAutoIfUnchanged($row['id'], $row['name'], $after, $version)) {
                    ++$result->skippedConcurrent;

                    continue;
                }

                $this->count($result, $row, $after, $sampleLimit);
            }
        } while (self::BATCH_SIZE === \count($batch));

        $this->logger->info('Counterparty display name refresh finished.', [
            'dryRun' => $dryRun,
            'rulesVersion' => $version,
            'processed' => $result->processed,
            'changed' => $result->changed,
            'becameUnrecognised' => $result->becameUnrecognised,
            'unchanged' => $result->unchanged,
            'unrecognised' => $result->unrecognised,
            'skippedConcurrent' => $result->skippedConcurrent,
        ]);

        return $result;
    }

    /**
     * @param array{id: string, company_id: string, name: string, display_name: ?string} $row
     */
    private function count(CounterpartyDisplayNameRefreshResult $result, array $row, ?string $after, int $sampleLimit): void
    {
        if (null === $after) {
            ++$result->unrecognised;
            if (\count($result->unrecognisedSamples) < $sampleLimit) {
                $result->unrecognisedSamples[] = ['id' => $row['id'], 'companyId' => $row['company_id'], 'raw' => $row['name']];
            }
        }

        if ($after === $row['display_name']) {
            ++$result->unchanged;

            return;
        }

        ++$result->changed;
        if (null === $after) {
            ++$result->becameUnrecognised;
        } elseif (\count($result->changedSamples) < $sampleLimit) {
            $result->changedSamples[] = ['id' => $row['id'], 'companyId' => $row['company_id'], 'raw' => $row['name'], 'before' => $row['display_name'], 'after' => $after];
        }
    }
}
